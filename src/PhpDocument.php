<?php
declare(strict_types = 1);

namespace LanguageServer;

use LanguageServer\Index\Index;
use LanguageServer\Protocol\{
    Diagnostic, Position, Range
};
use Microsoft\PhpParser;
use Microsoft\PhpParser\Node;
use phpDocumentor\Reflection\DocBlockFactory;

class PhpDocument
{
    /**
     * The PHPParser instance
     *
     * @var PhpParser\Parser
     */
    private $parser;

    /**
     * The DocBlockFactory instance to parse docblocks
     *
     * @var DocBlockFactory
     */
    private $docBlockFactory;

    /**
     * The DefinitionResolver instance to resolve reference nodes to definitions
     *
     * @var DefinitionResolver
     */
    private $definitionResolver;

    /**
     * @var Index
     */
    private $index;

    /**
     * The URI of the document
     *
     * @var string
     */
    private $uri;

    /**
     * The content of the document
     *
     * @var string
     */
    private $content;

    /**
     * The AST of the document
     *
     * @var Node\SourceFileNode
     */
    private $sourceFileNode;

    /**
     * Map from fully qualified name (FQN) to Definition
     *
     * @var Definition[]
     */
    private $definitions;

    /**
     * Map from fully qualified name (FQN) to Node
     *
     * @var Node
     */
    private $definitionNodes;

    /**
     * Map from fully qualified name (FQN) to array of nodes that reference the symbol
     *
     * @var Node[][]
     */
    private $referenceNodes;

    /**
     * Diagnostics for this document that were collected while parsing
     *
     * @var Diagnostic[]
     */
    private $diagnostics;

    /**
     * @param string $uri The URI of the document
     * @param string $content The content of the document
     * @param Index $index The Index to register definitions and references to
     * @param PhpParser\Parser $parser The PhpParser instance
     * @param DocBlockFactory $docBlockFactory The DocBlockFactory instance to parse docblocks
     * @param DefinitionResolver $definitionResolver The DefinitionResolver to resolve definitions to symbols in the workspace
     */
    public function __construct(
        string $uri,
        string $content,
        Index $index,
        $parser,
        DocBlockFactory $docBlockFactory,
        DefinitionResolver $definitionResolver
    ) {
        $this->uri = $uri;
        $this->index = $index;
        $this->parser = $parser;
        $this->docBlockFactory = $docBlockFactory;
        $this->definitionResolver = $definitionResolver;
        $this->updateContent($content);
    }

    /**
     * Get all references of a fully qualified name
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return Node[]
     */
    public function getReferenceNodesByFqn(string $fqn)
    {
        return isset($this->referenceNodes) && isset($this->referenceNodes[$fqn]) ? $this->referenceNodes[$fqn] : null;
    }

    /**
     * Updates the content on this document.
     * Re-parses a source file, updates symbols and reports parsing errors
     * that may have occurred as diagnostics.
     *
     * @param string $content
     * @return void
     */
    public function updateContent(string $content)
    {
        $this->content = $content;

        // Unregister old definitions
        if (isset($this->definitions)) {
            foreach ($this->definitions as $fqn => $definition) {
                $this->index->removeDefinition($fqn);
            }
        }

        // Unregister old references
        if (isset($this->referenceNodes)) {
            foreach ($this->referenceNodes as $fqn => $node) {
                $this->index->removeReferenceUri($fqn, $this->uri);
            }
        }

        $this->referenceNodes = null;
        $this->definitions = null;
        $this->definitionNodes = null;

        $treeAnalyzer = new TreeAnalyzer($this->parser, $content, $this->docBlockFactory, $this->definitionResolver, $this->uri);

        $this->diagnostics = $treeAnalyzer->getDiagnostics();

        $this->definitions = $treeAnalyzer->getDefinitions();

        $this->definitionNodes = $treeAnalyzer->getDefinitionNodes();

        $this->referenceNodes = $treeAnalyzer->getReferenceNodes();

        foreach ($this->definitions as $fqn => $definition) {
            $this->index->setDefinition($fqn, $definition);
        }

        // Register this document on the project for references
        foreach ($this->referenceNodes as $fqn => $nodes) {
            $this->index->addReferenceUri($fqn, $this->uri);
        }

        $this->sourceFileNode = $treeAnalyzer->getSourceFileNode();
    }

    /**
     * Returns array of TextEdit changes to format this document.
     *
     * @return \LanguageServer\Protocol\TextEdit[]
     */
    public function getFormattedText()
    {
        if (empty($this->content)) {
            return [];
        }
        return Formatter::format($this->content, $this->uri);
    }

    /**
     * Returns this document's text content.
     *
     * @return string
     */
    public function getContent()
    {
        return $this->content;
    }

    /**
     * Returns this document's diagnostics
     *
     * @return Diagnostic[]
     */
    public function getDiagnostics()
    {
        return $this->diagnostics;
    }

    /**
     * Returns the URI of the document
     *
     * @return string
     */
    public function getUri(): string
    {
        return $this->uri;
    }

    /**
     * Returns the AST of the document
     *
     * @return Node\SourceFileNode|null
     */
    public function getSourceFileNode()
    {
        return $this->sourceFileNode;
    }

    /**
     * Returns the node at a specified position
     *
     * @param Position $position
     * @return Node|null
     */
    public function getNodeAtPosition(Position $position)
    {
        if ($this->sourceFileNode === null) {
            return null;
        }

        $offset = $position->toOffset($this->sourceFileNode->getFileContents());
        $node = $this->sourceFileNode->getDescendantNodeAtPosition($offset);
        if ($node !== null && $node->getStart() > $offset) {
            return null;
        }
        return $node;
    }

    /**
     * Returns a range of the content
     *
     * @param Range $range
     * @return string|null
     */
    public function getRange(Range $range)
    {
        if ($this->content === null) {
            return null;
        }
        $start = $range->start->toOffset($this->content);
        $length = $range->end->toOffset($this->content) - $start;
        return substr($this->content, $start, $length);
    }

    /**
     * Returns the definition node for a fully qualified name
     *
     * @param string $fqn
     * @return Node|null
     */
    public function getDefinitionNodeByFqn(string $fqn)
    {
        return $this->definitionNodes[$fqn] ?? null;
    }

    /**
     * Returns a map from fully qualified name (FQN) to Nodes defined in this document
     *
     * @return Node[]
     */
    public function getDefinitionNodes()
    {
        return $this->definitionNodes;
    }

    /**
     * Returns a map from fully qualified name (FQN) to Definition defined in this document
     *
     * @return Definition[]
     */
    public function getDefinitions()
    {
        return $this->definitions ?? [];
    }

    /**
     * Returns true if the given FQN is defined in this document
     *
     * @param string $fqn The fully qualified name of the symbol
     * @return bool
     */
    public function isDefined(string $fqn): bool
    {
        return isset($this->definitions[$fqn]);
    }
}
