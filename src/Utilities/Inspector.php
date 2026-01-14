<?php namespace Rackage\Utilities;

/**
 * Inspector - Docblock Filter Parser
 *
 * Extracts @before and @after filter annotations from controller method docblocks.
 * Used by the Router to implement method-level middleware/filters.
 *
 * Filter Syntax:
 *
 *   Single method (on current controller):
 *     @before checkAuth
 *     @after logActivity
 *
 *   External class and method:
 *     @before AuthFilter, check
 *     @before Filters\Security, validateToken
 *
 *   Multiple filters:
 *     @before checkAuth
 *     @before checkAdmin
 *     @after clearCache
 *     @after logActivity
 *
 * Usage:
 *   $docblock = "/** @before checkAuth @after logActivity *\/";
 *   $filters = Inspector::checkFilter($docblock);
 *
 *   // Returns:
 *   // [
 *   //   'before' => ['checkAuth'],
 *   //   'after' => ['logActivity']
 *   // ]
 *
 * Filter Format:
 *   - Method only: "methodName"
 *   - Class + method: "ClassName, methodName" or "ClassName,methodName"
 *   - Commas and extra spaces are automatically cleaned
 *
 * @author Geoffrey Okongo <code@rachie.dev>
 * @copyright 2015 - 2050 Geoffrey Okongo
 * @category Rackage
 * @package Rackage\Utilities\Inspector
 * @link https://github.com/glivers/rackage
 * @license http://opensource.org/licenses/MIT MIT License
 * @version 2.0.2
 */

class Inspector {

    /**
     * Extract @before and @after filters from docblock
     *
     * Parses docblock comments to find filter annotations.
     * Supports both single method names and class/method pairs.
     *
     * Examples:
     *   Input:  "/** @before checkAuth @before validateInput @after log *\/"
     *   Output: ['before' => [['checkAuth'], ['validateInput']], 'after' => [['log']]]
     *
     *   Input:  "/** @before AuthFilter check *\/"
     *   Output: ['before' => [['AuthFilter', 'check']]]
     *
     *   Input:  "/** @param string $id *\/"  (no filters)
     *   Output: []
     *
     * Notes:
     *   - Commas are automatically stripped from filter names
     *   - Extra whitespace is trimmed
     *   - Returns empty array if no filters found (not false)
     *   - Each @before/@after can have 1 parameter (method) or 2 (class, method)
     *
     * @param string $comment_string The docblock comment string
     * @return array Array with 'before' and/or 'after' keys, or empty array
     */
    public static function checkFilter($comment_string)
    {
        // Match only @before and @after annotations (ignore @param, @return, etc.)
        $pattern = "#@(before|after)\s+([a-zA-Z0-9\\\\_,\s]+)#";

        preg_match_all($pattern, $comment_string, $matches, PREG_SET_ORDER);

        // No filters found, return empty array
        if (empty($matches)) {
            return [];
        }

        $beforeFilters = [];
        $afterFilters = [];

        // Process each @before or @after annotation
        foreach ($matches as $match) {
            $filterType = $match[1];  // "before" or "after"
            $filterValue = $match[2]; // "checkAuth" or "AuthFilter, check"

            // Split by space to get individual parameters
            $parts = preg_split('/\s+/', trim($filterValue));

            // Clean each part (remove commas, trim whitespace, remove empties)
            $cleanParts = [];
            foreach ($parts as $part) {
                $cleaned = trim(str_replace(',', '', $part));
                if (!empty($cleaned)) {
                    $cleanParts[] = $cleaned;
                }
            }

            // Add to appropriate filter array (as nested array)
            if ($filterType === 'before') {
                $beforeFilters[] = $cleanParts;
            } else {
                $afterFilters[] = $cleanParts;
            }
        }

        // Build return array
        $result = [];
        if (!empty($beforeFilters)) {
            $result['before'] = $beforeFilters;
        }
        if (!empty($afterFilters)) {
            $result['after'] = $afterFilters;
        }

        return $result;
    }
}
