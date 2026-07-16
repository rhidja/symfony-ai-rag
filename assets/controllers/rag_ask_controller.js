import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['question', 'submit', 'result', 'answer', 'sources', 'error'];

    async submit(event) {
        event.preventDefault();

        const question = this.questionTarget.value.trim();
        if ('' === question) {
            return;
        }

        this.resultTarget.classList.add('d-none');
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

            this.answerTarget.textContent = data.answer;
            this.sourcesTarget.innerHTML = '';
            (data.sources || []).forEach((source) => {
                const li = document.createElement('li');
                li.textContent = source;
                this.sourcesTarget.appendChild(li);
            });

            this.resultTarget.classList.remove('d-none');
        } catch (error) {
            this.errorTarget.textContent = error.message;
            this.errorTarget.classList.remove('d-none');
        } finally {
            this.submitTarget.disabled = false;
            this.submitTarget.textContent = 'Envoyer';
        }
    }
}
