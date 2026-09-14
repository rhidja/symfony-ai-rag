<?php

namespace App\Tests\Integration\Service\Rag;

use App\Service\Rag\RagAgentQueryService;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Toolbox\Source\Source;
use Symfony\AI\Agent\Toolbox\Source\SourceCollection;
use Symfony\AI\Platform\Metadata\Metadata;
use Symfony\AI\Platform\Result\ResultInterface;

final class RagAgentQueryServiceTest extends TestCase
{
    public function testAskReturnsAnswerWithSourcesFromToolMetadata(): void
    {
        $sources = new SourceCollection([
            new Source('/docs/manual.pdf', '/docs/manual.pdf', 'Reset instructions.'),
        ]);

        $result = $this->createMock(ResultInterface::class);
        $result->method('getContent')->willReturn('You can reset the device by holding the button.');
        $result->method('getMetadata')->willReturn(new Metadata(['sources' => $sources]));

        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->willReturn($result);

        $service = new RagAgentQueryService($agent);

        $answer = $service->ask('How do I reset the device?');

        self::assertSame('You can reset the device by holding the button.', $answer->answer);
        self::assertSame(['/docs/manual.pdf'], $answer->sources);
    }

    public function testAskReturnsEmptySourcesWhenNoToolWasCalled(): void
    {
        $result = $this->createMock(ResultInterface::class);
        $result->method('getContent')->willReturn('I do not know.');
        $result->method('getMetadata')->willReturn(new Metadata());

        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn($result);

        $service = new RagAgentQueryService($agent);

        $answer = $service->ask('What is the meaning of life?');

        self::assertSame('I do not know.', $answer->answer);
        self::assertSame([], $answer->sources);
    }
}
