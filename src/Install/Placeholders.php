<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

use Obrazmisli\Agentio\Runtime\MergePolicy;

/**
 * Values substituted into the stubs: {{project}}, {{base_branch}}, {{merge_policy}} and {{kb.<key>}}.
 * A knowledge base article whose id is unknown is rendered as its title in quotes.
 */
final readonly class Placeholders
{
    /**
     * @param  array<string, string>  $kb  Knowledge base key => article id, e.g. "overview" => "TP-A-1"
     */
    public function __construct(
        public string $project,
        public string $baseBranch,
        public MergePolicy $mergePolicy,
        public array $kb = [],
    ) {}

    /**
     * @param  array<string, string>  $kb
     */
    public function withKb(array $kb): self
    {
        return new self($this->project, $this->baseBranch, $this->mergePolicy, $kb);
    }

    public function render(string $text): string
    {
        $text = strtr($text, [
            '{{project}}' => $this->project,
            '{{base_branch}}' => $this->baseBranch,
            '{{merge_policy}}' => $this->mergePolicy->value,
        ]);

        return (string) preg_replace_callback(
            '/\{\{kb\.([a-z0-9_.]+)\}\}/',
            fn (array $match): string => $this->kb[$match[1]] ?? '«'.(KnowledgeBase::ARTICLES[$match[1]][0] ?? $match[1]).'»',
            $text,
        );
    }
}
