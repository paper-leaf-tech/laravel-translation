<?php

namespace PaperleafTech\LaravelTranslation\Services;

use Illuminate\Support\Facades\File;
use PhpParser\Node;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\CloningVisitor;
use PhpParser\ParserFactory;
use PhpParser\PrettyPrinter\Standard as StandardPrinter;

class TranslationFileWriter
{
    /**
     * Apply $updates (key-path => new value) to the PHP array returned by $filePath.
     * Only existing keys whose current value is a simple string literal are modified;
     * the file's comments, blank lines, indentation, and quote style are preserved.
     *
     * @param  array<string,string>  $updates  Flat map keyed by file-relative dotted path
     *                                         (e.g., 'failed', 'email.format').
     * @return array{updated:list<string>, skipped_missing:list<string>, skipped_non_string:list<string>}
     */
    public function updateFile(string $filePath, array $updates): array
    {
        $result = [
            'updated' => [],
            'skipped_missing' => [],
            'skipped_non_string' => [],
        ];

        if (empty($updates)) {
            return $result;
        }

        if (! File::exists($filePath)) {
            $result['skipped_missing'] = array_keys($updates);

            return $result;
        }

        $source = File::get($filePath);

        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        $oldStmts = $parser->parse($source);
        $oldTokens = $parser->getTokens();

        if ($oldStmts === null) {
            $result['skipped_missing'] = array_keys($updates);

            return $result;
        }

        $returnArray = $this->locateReturnedArray($oldStmts);
        if ($returnArray === null) {
            $result['skipped_missing'] = array_keys($updates);

            return $result;
        }

        // Clone the AST so format-preserving printer can diff old vs new.
        $cloningTraverser = new NodeTraverser(new CloningVisitor());
        $newStmts = $cloningTraverser->traverse($oldStmts);
        $newReturnArray = $this->locateReturnedArray($newStmts);

        // Build a path => String_ map of values that can be surgically updated.
        $pathToString = [];
        $nonStringPaths = [];
        $this->indexArray($newReturnArray, '', $pathToString, $nonStringPaths);

        foreach ($updates as $path => $newValue) {
            if (isset($pathToString[$path])) {
                $pathToString[$path]->value = $newValue;
                $result['updated'][] = $path;
            } elseif ($this->hasNonStringAncestor($path, $nonStringPaths)) {
                $result['skipped_non_string'][] = $path;
            } else {
                $result['skipped_missing'][] = $path;
            }
        }

        if (empty($result['updated'])) {
            return $result;
        }

        $printer = new StandardPrinter();
        $printed = $printer->printFormatPreserving($newStmts, $oldStmts, $oldTokens);

        if ($printed !== $source) {
            File::put($filePath, $printed);
        }

        return $result;
    }

    /**
     * Return the Array_ node from the file's top-level `return [...];` statement,
     * or null if the file doesn't return an array literal at the top level.
     *
     * @param  array<int, Node\Stmt>  $stmts
     */
    protected function locateReturnedArray(array $stmts): ?Array_
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Return_ && $stmt->expr instanceof Array_) {
                return $stmt->expr;
            }
        }

        return null;
    }

    /**
     * Walk an Array_ node and record the dotted path of every string-literal value
     * we can safely update. Items whose value is not a simple String_ (and not a
     * nested Array_) are recorded as non-string paths so the caller can distinguish
     * "missing key" from "key exists but we can't touch it".
     *
     * @param  array<string, String_>  $pathToString
     * @param  array<int, string>  $nonStringPaths
     */
    protected function indexArray(Array_ $array, string $prefix, array &$pathToString, array &$nonStringPaths): void
    {
        foreach ($array->items as $item) {
            if (! $item instanceof ArrayItem || ! $item->key instanceof String_) {
                continue;
            }

            $path = $prefix === '' ? $item->key->value : $prefix.'.'.$item->key->value;

            if ($item->value instanceof String_) {
                $pathToString[$path] = $item->value;
            } elseif ($item->value instanceof Array_) {
                $this->indexArray($item->value, $path, $pathToString, $nonStringPaths);
            } else {
                $nonStringPaths[] = $path;
            }
        }
    }

    /**
     * Determine whether $path (or any prefix of it) is recorded as a non-string
     * value in the file. Used to distinguish "we found this key but can't touch it"
     * from "this key isn't in the file at all".
     *
     * @param  array<int, string>  $nonStringPaths
     */
    protected function hasNonStringAncestor(string $path, array $nonStringPaths): bool
    {
        foreach ($nonStringPaths as $p) {
            if ($path === $p || str_starts_with($path, $p.'.')) {
                return true;
            }
        }

        return false;
    }
}
