<?php

declare(strict_types=1);

namespace Obrazmisli\Agentio\Install;

/**
 * Values substituted into the stubs: {{project}}, {{base_branch}} (the development branch), {{production_branch}}
 * and {{kb.<key>}}.
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
        public array $kb = [],
        public string $productionBranch = 'main',
    ) {}

    /**
     * @param  array<string, string>  $kb
     */
    public function withKb(array $kb): self
    {
        return new self($this->project, $this->baseBranch, $kb, $this->productionBranch);
    }

    public function render(string $text): string
    {
        $text = strtr($text, [
            '{{project}}' => $this->project,
            '{{base_branch}}' => $this->baseBranch,
            '{{production_branch}}' => $this->productionBranch,
        ]);

        return (string) preg_replace_callback(
            '/\{\{kb\.([a-z0-9_.]+)\}\}/',
            fn (array $match): string => $this->kb[$match[1]] ?? '«'.(KnowledgeBase::ARTICLES[$match[1]][0] ?? $match[1]).'»',
            $text,
        );
    }
}
