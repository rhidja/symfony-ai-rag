import { Controller } from '@hotwired/stimulus';
import { marked } from 'marked';
import DOMPurify from 'dompurify';

export default class extends Controller {
    static targets = ['messages', 'empty', 'question', 'submit', 'error', 'clear'];

    connect() {
        this.loadHistory();
    }

    async loadHistory() {
        try {
            const response = await fetch('/api/rag/history');
            if (!response.ok) {
                return;
            }

            const messages = await response.json();
            messages.forEach((message) => this.renderExchange(message.question, message.answer, message.sources));
            this.updateEmptyState();
        } catch {
            // History is a convenience; a failure to load it must not block the chat.
        }
    }

    async submit(event) {
        event.preventDefault();

        const question = this.questionTarget.value.trim();
        if ('' === question) {
            return;
        }

        this.errorTarget.classList.add('d-none');
        this.submitTarget.disabled = true;
        this.submitTarget.textContent = 'Recherche en cours…';

        try {
            const response = await fetch('/api/rag/ask', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ question }),
            });

            const data = await response.json();

            if (!response.ok) {
                throw new Error(data.error || 'Une erreur est survenue.');
            }

            this.renderExchange(question, data.answer, data.sources || []);
            this.updateEmptyState();
            this.questionTarget.value = '';
        } catch (error) {
            this.errorTarget.textContent = error.message;
            this.errorTarget.classList.remove('d-none');
        } finally {
            this.submitTarget.disabled = false;
            this.submitTarget.textContent = 'Envoyer';
        }
    }

    async clear(event) {
        event.preventDefault();

        this.clearTarget.disabled = true;

        try {
            await fetch('/api/rag/history', { method: 'DELETE' });
            this.messagesTarget.innerHTML = '';
            this.updateEmptyState();
        } finally {
            this.clearTarget.disabled = false;
        }
    }

    renderExchange(question, answer, sources) {
        const exchange = document.createElement('div');
        exchange.className = 'mb-4';

        const questionBubble = document.createElement('div');
        questionBubble.className = 'd-flex justify-content-end mb-2';
        questionBubble.innerHTML = `
            <div class="bg-primary text-white rounded-3 px-3 py-2" style="max-width: 80%;"></div>
        `;
        questionBubble.firstElementChild.textContent = question;
        exchange.appendChild(questionBubble);

        const answerBubble = document.createElement('div');
        answerBubble.className = 'd-flex justify-content-start';
        const card = document.createElement('div');
        card.className = 'card shadow-sm';
        card.style.maxWidth = '80%';
        const cardBody = document.createElement('div');
        cardBody.className = 'card-body';
        const answerContent = document.createElement('div');
        answerContent.className = 'mb-0 rag-answer';
        answerContent.innerHTML = DOMPurify.sanitize(marked.parse(answer));
        cardBody.appendChild(answerContent);

        if (sources.length > 0) {
            const sourcesWrapper = document.createElement('div');
            sourcesWrapper.className = 'mt-2 pt-2 border-top';
            const sourcesTitle = document.createElement('div');
            sourcesTitle.className = 'text-muted small mb-1';
            sourcesTitle.textContent = 'Sources';
            sourcesWrapper.appendChild(sourcesTitle);
            const sourcesList = document.createElement('ul');
            sourcesList.className = 'list-unstyled small text-muted mb-0';
            sources.forEach((source) => {
                const li = document.createElement('li');
                li.textContent = source;
                sourcesList.appendChild(li);
            });
            sourcesWrapper.appendChild(sourcesList);
            cardBody.appendChild(sourcesWrapper);
        }

        card.appendChild(cardBody);
        answerBubble.appendChild(card);
        exchange.appendChild(answerBubble);

        this.messagesTarget.appendChild(exchange);
        this.messagesTarget.scrollTop = this.messagesTarget.scrollHeight;
    }

    updateEmptyState() {
        this.emptyTarget.classList.toggle('d-none', this.messagesTarget.children.length > 0);
    }
}
