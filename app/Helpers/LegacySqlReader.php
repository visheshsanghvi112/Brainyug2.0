<?php

namespace App\Helpers;

use Exception;

class LegacySqlReader
{
    /**
     * Parse a large SQL dump file memory-efficiently and yield rows for a specific table.
     *
     * @param string $filepath Absolute path to the .sql file
     * @param string $tableName The table name to extract (without backticks)
     * @return \Generator Yields associate array if headers exist, or numeric array
     */
    public static function streamTableRows(string $filepath, string $tableName)
    {
        if (!file_exists($filepath)) {
            throw new Exception("SQL File not found: " . $filepath);
        }

        $handle = fopen($filepath, "r");
        if (!$handle) {
            throw new Exception("Cannot open file: " . $filepath);
        }

        $headerFields = [];

        // E.g., CREATE TABLE `salt_master` ( `id` int(11) ...
        // We capture headers to yield associative arrays if possible.
        $statement = '';
        $inInsert = false;
        
        while (($line = fgets($handle)) !== false) {
            $lineTrimmed = trim($line);
            
            // Start of our target table INSERT
            if (!$inInsert) {
                $upperLine = strtoupper($lineTrimmed);
                $upperTableName = strtoupper($tableName);
                if (strpos($upperLine, "INSERT INTO `$upperTableName`") === 0 || strpos($upperLine, "INSERT INTO $upperTableName") === 0) {
                    $inInsert = true;
                    $statement = $lineTrimmed;
                }
            } else {
                // We are inside the INSERT statement
                $statement .= ' ' . $lineTrimmed;
            }

            // End of SQL statement
            if ($inInsert && substr($lineTrimmed, -1) === ';') {
                $inInsert = false;
                
                // Now process the entire $statement
                $pos = stripos($statement, ' VALUES');
                if ($pos !== false) {
                    $insertPart = substr($statement, 0, $pos);
                    $valuesPart = trim(substr($statement, $pos + 7));
                    
                    if (preg_match('/\((.*?)\)/', $insertPart, $mCols)) {
                        $headerFields = array_map(function($col) {
                            return trim($col, "` '\"");
                        }, explode(',', $mCols[1]));
                    }

                    $rows = self::parseInsertValues($valuesPart);
                    foreach ($rows as $row) {
                        if (!empty($headerFields) && count($headerFields) === count($row)) {
                            yield array_combine($headerFields, $row);
                        } else {
                            yield $row;
                        }
                    }
                }
                $statement = '';
                $headerFields = [];
            }
        }

        fclose($handle);
    }

    /**
     * State machine to parse `(1, 'A', NULL), (2, 'B', 'O\'brian');`
     * Works even if the string has escaped quotes.
     */
    private static function parseInsertValues(string $sqlValues): array
    {
        $rows = [];
        $currentRow = [];
        $currentVal = '';
        
        $len = strlen($sqlValues);
        $inString = false;
        $inRow = false;
        $escapeNext = false;

        for ($i = 0; $i < $len; $i++) {
            $char = $sqlValues[$i];

            if ($escapeNext) {
                $currentVal .= $char;
                $escapeNext = false;
                continue;
            }

            if ($char === '\\') {
                $escapeNext = true;
                // Keep the backslash internally or replace it depending on need,
                // Usually MySQL escapes like \' or \\. We'll skip the backslash for standard strings.
                continue; 
            }

            if ($char === "'" && !$inString) {
                $inString = true;
                continue;
            } elseif ($char === "'" && $inString) {
                // Check if next char is also quote (which is SQL escape for quote)
                if ($i + 1 < $len && $sqlValues[$i+1] === "'") {
                    $currentVal .= "'";
                    $i++; // Skip the next quote
                } else {
                    $inString = false;
                }
                continue;
            }

            if (!$inString) {
                if ($char === '(' && !$inRow) {
                    $inRow = true;
                    $currentRow = [];
                    $currentVal = '';
                    continue;
                }

                if ($char === ')' && $inRow) {
                    $inRow = false;
                    $val = trim($currentVal);
                    if (strtoupper($val) === 'NULL') {
                        $currentRow[] = null;
                    } else {
                        $currentRow[] = $val;
                    }
                    $rows[] = $currentRow;
                    $currentVal = '';
                    continue;
                }

                if ($char === ',' && $inRow) {
                    $val = trim($currentVal);
                    if (strtoupper($val) === 'NULL') {
                        $currentRow[] = null;
                    } else {
                        $currentRow[] = $val;
                    }
                    $currentVal = '';
                    continue;
                }
            }

            // Append character
            if ($inRow) {
                $currentVal .= $char;
            }
        }

        return $rows;
    }
}
