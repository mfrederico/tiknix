<?php
/**
 * Pipeline\ModelList — where a provider's model list lives for each kind of agent, and what is
 * read out of the two shapes providers answer in. The URLs were checked against the live
 * providers on 2026-10-04 (z.ai, DeepSeek, OpenRouter, Ollama and Anthropic answer at
 * <base>/v1/models; Kimi 404s there and answers beside its /anthropic mount).
 */
namespace tests\unit;

use PHPUnit\Framework\TestCase;
use app\Pipeline\ModelList;

class ModelListTest extends TestCase {
    public function testWhereEachKindsListLives(): void {
        $this->assertSame(['https://api.z.ai/api/anthropic/v1/models', 'https://api.z.ai/api/v1/models'], ModelList::urls('cli', 'https://api.z.ai/api/anthropic/'));
        $this->assertSame(['https://api.moonshot.ai/anthropic/v1/models', 'https://api.moonshot.ai/v1/models'], ModelList::urls('cli', 'https://api.moonshot.ai/anthropic'));
        $this->assertSame(['https://openrouter.ai/api/v1/models'], ModelList::urls('cli', 'https://openrouter.ai/api'));
        $this->assertSame(['https://integrate.api.nvidia.com/v1/models'], ModelList::urls('openai', 'https://integrate.api.nvidia.com/v1'));
        $this->assertSame(['https://openrouter.ai/api/v1/embeddings/models', 'https://openrouter.ai/api/v1/models'], ModelList::urls('embeddings', 'https://openrouter.ai/api/v1'));
        $this->assertSame(['http://10.0.0.5:11434/api/tags'], ModelList::urls('decision', 'http://10.0.0.5:11434/v1'));
        $this->assertSame(['http://10.0.0.5:11434/api/tags'], ModelList::urls('decision', 'http://10.0.0.5:11434'));
        $this->assertSame([], ModelList::urls('member', 'https://x.example'));
        $this->assertSame([], ModelList::urls('openai', ''));
    }

    public function testBothAnswerShapesAndNothingElse(): void {
        $this->assertSame(['a/b', 'c'], ModelList::parse('{"object":"list","data":[{"id":"a/b"},{"id":"c"},{"id":"a/b"},{"nope":1}]}'));
        $this->assertSame(['tev1:0.8b', 'nimble'], ModelList::parse('{"models":[{"name":"tev1:0.8b","size":1},{"name":"nimble"}]}'));
        $this->assertNull(ModelList::parse('<html>'));
        $this->assertNull(ModelList::parse('{"code":1001,"msg":"Authentication parameter not received"}'), 'a 200 that is really a refusal is not an empty list');
    }

    public function testEmbeddingModelsLeadAnEmbeddingsAgentsList(): void {
        $ids = ['nvidia/nemotron-3-super', 'snowflake/arctic-embed-l', 'meta/llama', 'nvidia/nemotron-3-embed-1b'];
        $this->assertSame(['nvidia/nemotron-3-embed-1b', 'snowflake/arctic-embed-l', 'meta/llama', 'nvidia/nemotron-3-super'], ModelList::rank('embeddings', $ids));
        $this->assertSame(['meta/llama', 'nvidia/nemotron-3-embed-1b', 'nvidia/nemotron-3-super', 'snowflake/arctic-embed-l'], ModelList::rank('openai', $ids));
    }

    public function testNoEndpointIsSaidNotGuessed(): void {
        $this->expectExceptionMessage('give the endpoint first');
        ModelList::fetch('openai', '', '');
    }
}
