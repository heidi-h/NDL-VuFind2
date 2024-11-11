<?php

/**
 * Console command: convert style files from LESS to SCSS (Sass).
 *
 * PHP version 8
 *
 * Copyright (C) The National Library of Finland 2024.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License version 2,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category VuFind
 * @package  Console
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */

namespace FinnaConsole\Command\Util;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use VuFind\Config\PathResolver;

use function array_key_exists;
use function array_slice;
use function count;
use function dirname;
use function in_array;
use function is_string;
use function strlen;

/**
 * Console command: convert style files from LESS to SCSS (Sass).
 *
 * @category VuFind
 * @package  Console
 * @author   Ere Maijala <ere.maijala@helsinki.fi>
 * @license  http://opensource.org/licenses/gpl-2.0.php GNU General Public License
 * @link     https://vufind.org/wiki/development Wiki
 */
#[AsCommand(
    name: 'util/lessToScss',
    description: 'LESS to SCSS conversion'
)]
class LessToScssCommand extends Command
{
    public const VARIABLE_CHARS = '[a-zA-Z0-9_-]';

    /**
     * Include paths
     *
     * @var array
     */
    protected $includePaths = [];

    /**
     * Console output
     *
     * @var OutputInterface
     */
    protected $output = null;

    /**
     * All variables with the last occurrence taking precedence (like in lesscss)
     *
     * @var array
     */
    protected $allLessVars = [];

    /**
     * Source directory (LESS)
     *
     * @var string
     */
    protected $sourceDir = '';

    /**
     * Target directory (SCSS)
     *
     * @var string
     */
    protected $targetDir = '';

    /**
     * An array tracking all processed files
     *
     * @var array
     */
    protected $allFiles = [];

    /**
     * File to use for all added variables
     *
     * @var ?string
     */
    protected $variablesFile = null;

    /**
     * Files excluded from processing
     *
     * @var array
     */
    protected $excludedFiles = [];

    /**
     * Substitutions (regexp and string replace)
     *
     * @var array
     */
    protected $substitutions = [];

    /**
     * Whether to enable SCSS in target theme(s)
     *
     * @var bool
     */
    protected $enableScss = false;

    /**
     * Patterns for files that must not use the !default flag for variables
     *
     * @var array
     */
    protected $noDefaultFiles = [];

    /**
     * Constructor
     *
     * @param PathResolver $pathResolver Config path resolver
     */
    public function __construct(protected PathResolver $pathResolver)
    {
        parent::__construct();
    }

    /**
     * Configure the command.
     *
     * @return void
     */
    protected function configure()
    {
        $this
            ->setHelp('Converts LESS styles to SCSS')
            ->addOption(
                'variables_file',
                null,
                InputOption::VALUE_REQUIRED,
                'File to use for added SCSS variables (may be relative to the target directory)'
            )
            ->addOption(
                'include_path',
                'I',
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Include directories for SCSS parser'
            )
            ->addOption(
                'exclude',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Files to skip as main LESS files (fnmatch patterns)'
            )
            ->addOption(
                'no_default',
                null,
                InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
                'Files where variable definitions must not include the !default flag (fnmatch patterns relative to'
                . ' the target directory)'
            )
            ->addOption(
                'enable_scss',
                null,
                InputOption::VALUE_NONE,
                'If specified, enables SCSS in the target theme(s)',
            )
            ->addOption(
                'check_file_timestamps',
                null,
                InputOption::VALUE_REQUIRED,
                'If specified, enables file timestamp check that skips conversion if the files in the target directory'
                . ' have changed since last conversion.'
            )
            ->addOption(
                'check_config',
                null,
                InputOption::VALUE_NONE,
                'When enabled, checks style.ini in target directories and skips conversion if mode is already scss or'
                . ' skip_conversion option is set.'
            )
            ->addArgument(
                'main_file',
                InputArgument::REQUIRED | InputArgument::IS_ARRAY,
                'Main LESS file to use as entry point. Can also be a glob pattern.'
            );
    }

    /**
     * Run the command.
     *
     * @param InputInterface  $input  Input object
     * @param OutputInterface $output Output object
     *
     * @return int 0 for success
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->output = $output;
        $this->substitutions = $this->getSubstitutions();
        $this->includePaths = $input->getOption('include_path');
        $this->excludedFiles = $input->getOption('exclude');
        $this->noDefaultFiles = $input->getOption('no_default');
        $this->enableScss = $input->getOption('enable_scss');
        $variablesFile = $input->getOption('variables_file');
        $patterns = $input->getArgument('main_file');
        $checkConfig = $input->getOption('check_config');
        $timestampFile = $input->getOption('check_file_timestamps');

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) as $mainFile) {
                foreach ($this->excludedFiles as $exclude) {
                    if (fnmatch($exclude, $mainFile)) {
                        continue 2;
                    }
                }

                $this->allFiles = [];
                $this->allLessVars = [];

                $this->sourceDir = dirname($mainFile);
                $this->targetDir = preg_replace('/\/less\b/', '/scss', $this->sourceDir);
                $this->variablesFile = $variablesFile
                    ? $this->sourceDir . '/' . preg_replace('/\.scss$/', '', $variablesFile)
                    : null;

                if ($checkConfig) {
                    // Check style.ini in target directory and skip conversion if appropriate:
                    $styleIni = $this->targetDir . '/style.ini';
                    if (file_exists($styleIni)) {
                        $styleConfig = parse_ini_file($styleIni, true);
                        if (
                            (($styleConfig['General']['mode'] ?? null) === 'scss')
                            || ($styleConfig['General']['skip_conversion'] ?? false)
                        ) {
                            $this->output->writeln("<info>Skipping $mainFile due to configuration in style.ini");
                            continue;
                        }
                    }
                }
                if (
                    $timestampFile
                    && $this->filesNewerThanTimestampFile($timestampFile, $mainFile)
                ) {
                    $this->output->writeln(
                        "<info>Skipping $mainFile - Files in target directory modified since last conversion"
                    );
                    continue;
                }

                $this->output->writeln("<info>Processing $mainFile");
                // First read all vars:
                if (!$this->discoverLess($mainFile, $this->allLessVars)) {
                    return Command::FAILURE;
                }
                // Now do changes:
                $currentVars = [];
                if (!$this->processFile($mainFile, $currentVars)) {
                    $this->error('Stop on failure');
                    return Command::FAILURE;
                }

                // Write out the target files:
                if (!$this->writeTargetFiles()) {
                    return Command::FAILURE;
                }

                // Update timestamp file if specified:
                if ($timestampFile) {
                    $timestampFilePath = $this->targetDir . "/$timestampFile";
                    if (!touch($timestampFilePath)) {
                        $this->error("Could not write timestamp file $timestampFilePath");
                        return Command::FAILURE;
                    }
                }
            }
        }
        return Command::SUCCESS;
    }

    /**
     * Discover less variables
     *
     * @param string $filename File name
     * @param array  $vars     Currently defined variables
     *
     * @return bool
     */
    protected function discoverLess(string $filename, array &$vars): bool
    {
        if (!$this->isReadableFile($filename)) {
            $this->error("File $filename does not exist or is not a readable file");
            return false;
        }
        $fileDir = dirname($filename);
        $lineNo = 0;
        $this->debug("Start processing $filename (discovery)", OutputInterface::VERBOSITY_DEBUG);
        $lines = file($filename, FILE_IGNORE_NEW_LINES);

        $inMixin = 0;
        $inComment = false;
        foreach ($lines as $line) {
            ++$lineNo;
            if (trim($line) === '') {
                continue;
            }
            $lineId = "$filename:$lineNo";
            [$line] = $this->extractComment($line);

            $cStart = strpos($line, '/*');
            $cEnd = strrpos($line, '*/');
            if (false !== $cStart && (false === $cEnd || $cEnd < $cStart)) {
                $inComment = true;
            } elseif (false !== $cEnd) {
                $inComment = false;
            }
            if ($inComment || preg_match('/^\s*\/\*.*\*\/\s*$/', $line)) {
                continue;
            }

            if (preg_match('/\.([\w\-]*)\s*\((.*)\)\s*\{/i', trim($line))) {
                $inMixin = $this->getBlockLevelChange($line);
                continue;
            }
            if ($inMixin) {
                $inMixin += $this->getBlockLevelChange($line);
            }
            if ($inMixin) {
                continue;
            }

            // Process variable declarations:
            $this->processLessVariables($lineId, $line, $vars);
            // Process import:
            if (!$this->processImports($lineId, $fileDir, $line, $vars, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Process a file
     *
     * @param string $filename File name
     * @param array  $vars     Currently defined variables
     *
     * @return bool
     */
    protected function processFile(string $filename, array &$vars): bool
    {
        if (!$this->isReadableFile($filename)) {
            $this->error("File $filename does not exist or is not a readable file");
            return false;
        }
        $fileDir = dirname($filename);
        $inSourceDir = $this->isInSourceDir($fileDir);
        $lineNo = 0;
        $this->debug("Start processing $filename (conversion)", OutputInterface::VERBOSITY_DEBUG);
        $lines = file($filename, FILE_IGNORE_NEW_LINES);

        // Process string substitutions
        if (!str_ends_with($filename, '.scss')) {
            $lines = explode(PHP_EOL, $this->processSubstitutions($filename, implode(PHP_EOL, $lines)));
            $this->updateFileCollection($filename, compact('lines', 'vars'));
        }

        $inMixin = 0;
        $inComment = false;
        $requiredVars = [];
        foreach ($lines as $idx => $line) {
            ++$lineNo;
            if (trim($line) === '') {
                continue;
            }
            $lineId = "$filename:$lineNo";

            [$line, $comments] = $this->extractComment($line);

            if (str_starts_with(trim($line), '@mixin ')) {
                $inMixin = $this->getBlockLevelChange($line);
                continue;
            }
            if ($inMixin) {
                $inMixin += $this->getBlockLevelChange($line);
            }
            if ($inMixin) {
                continue;
            }

            $cStart = strpos($line, '/*');
            $cEnd = strrpos($line, '*/');
            if (false !== $cStart && (false === $cEnd || $cEnd < $cStart)) {
                $inComment = true;
            } elseif (false !== $cEnd) {
                $inComment = false;
            }
            if ($inComment || preg_match('/^\s*\/\*.*\*\/\s*$/', $line)) {
                continue;
            }

            // Process variable declarations:
            $this->processScssVariables($lineId, $line, $vars);
            // Process imports:
            if (!$this->processImports($lineId, $fileDir, $line, $vars, false)) {
                return false;
            }

            // Collect variables that need to be defined:
            if ($inSourceDir) {
                if ($newVars = $this->checkVariables($lineId, $line, $vars)) {
                    $requiredVars = [
                        ...$requiredVars,
                        ...$newVars,
                    ];
                }
            }
            $lines[$idx] = $line . (null !== $comments ? "//$comments" : '');
        }

        $this->updateFileCollection($filename, compact('lines', 'requiredVars'));

        return true;
    }

    /**
     * Extract a single-line comment from a line
     *
     * @param string $line Line
     *
     * @return array remaining line and the comment (or null)
     */
    protected function extractComment(string $line): array
    {
        $result = '';
        $comment = null;
        $inSingle = false;
        $inDouble = false;
        $escape = false;
        if (!str_contains($line, '/')) {
            return [$line, null];
        }
        $len = strlen($line);
        for ($i = 0; $i < $len; $i++) {
            $ch = $line[$i];
            switch ($ch) {
                case '"':
                    if (!$inSingle) {
                        $inDouble = !$inDouble;
                    }
                    break;
                case "'":
                    if (!$inDouble) {
                        $inSingle = !$inSingle;
                    }
                    break;
                case '\\':
                    $escape = !$escape;
                    break;
                case '/':
                    // Check for a comment:
                    if (!$inSingle && !$inDouble && !$escape && ($line[$i + 1] ?? null) === '/') {
                        $comment = substr($line, $i + 2);
                        break 2;
                    }
                    break;
            }
            $result .= $ch;
        }
        return [$result, $comment];
    }

    /**
     * Check if path is in source directory
     *
     * @param string $path Path
     *
     * @return bool
     */
    protected function isInSourceDir(string $path): bool
    {
        return str_starts_with($path, $this->sourceDir) && !str_contains($path, '..');
    }

    /**
     * Check if path is in target directory
     *
     * @param string $path Path
     *
     * @return bool
     */
    protected function isInTargetDir(string $path): bool
    {
        return str_starts_with($path, $this->targetDir) && !str_contains($path, '..');
    }

    /**
     * Find variables in LESS
     *
     * @param string $lineId Line identifier for logging
     * @param string $line   Line
     * @param array  $vars   Currently defined variables
     *
     * @return ?array Array of required variables and their valuesm, or null on error
     */
    protected function processLessVariables(string $lineId, string $line, array &$vars): void
    {
        if (!preg_match('/^\s*\@(' . static::VARIABLE_CHARS . '+):\s*(.*?);?\s*$/', $line, $matches)) {
            return;
        }
        [, $var, $value] = $matches;
        $value = trim(preg_replace('/\s*!default\s*;?\s*$/', '', $value));
        if (array_key_exists($var, $vars)) {
            $this->debug(
                "$lineId: `$var: $value` overrides existing value `" . $vars[$var] . '`',
                OutputInterface::VERBOSITY_DEBUG
            );
        } else {
            $this->debug("$lineId: found `$var: $value`", OutputInterface::VERBOSITY_DEBUG);
        }

        $vars[$var] = $value;
    }

    /**
     * Find variables
     *
     * @param string $lineId Line identifier for logging
     * @param string $line   Line
     * @param array  $vars   Currently defined variables
     *
     * @return ?array Array of required variables and their valuesm, or null on error
     */
    protected function processScssVariables(string $lineId, string $line, array &$vars): void
    {
        if (!preg_match('/^\s*\$(' . static::VARIABLE_CHARS . '+):\s*(.*?);?$/', $line, $matches)) {
            return;
        }
        [, $var, $value] = $matches;
        $value = trim(preg_replace('/\s*!default\s*;?\s*$/', '', $value, -1, $count));
        $default = $count > 0;
        $existing = $vars[$var] ?? null;
        if ($existing) {
            if ($existing['default'] && !$default) {
                $this->debug(
                    "$lineId: `$var: $value` overrides default value `" . $vars[$var]['value'] . '`',
                    OutputInterface::VERBOSITY_DEBUG
                );
            } else {
                return;
            }
        } else {
            $this->debug("$lineId: found `$var: $value`", OutputInterface::VERBOSITY_DEBUG);
        }
        $vars[$var] = compact('value', 'default');
    }

    /**
     * Process imports
     *
     * @param string $lineId   Line identifier for logging
     * @param string $fileDir  Current file directory
     * @param string $line     Line
     * @param array  $vars     Currently defined variables
     * @param bool   $discover Whether to just discover files and their content
     *
     * @return bool
     */
    protected function processImports(string $lineId, string $fileDir, string $line, array &$vars, bool $discover): bool
    {
        if (!preg_match("/^\s*@import\s+['\"]([^'\"]+)['\"]\s*;/", $line, $matches)) {
            // Check for LESS import reference:
            if (!preg_match("/^\s*@import \/\*\(reference\)\*\/ ['\"]([^'\"]+)['\"]\s*;/", $line, $matches)) {
                return true;
            }
        }
        $import = $matches[1];
        if (str_ends_with($import, '.css')) {
            $this->debug("$lineId: skipping .css import");
            return true;
        }
        if (!($fullPath = $this->resolveImportFileName($import, $fileDir))) {
            $targetFileDir = str_replace($this->sourceDir, $this->targetDir, $fileDir);
            $targetFileDir = str_replace('/less', '/scss', $targetFileDir);
            $targetImport = str_replace('/less/', '/scss/', $import);
            if (!($fullPath = $this->resolveImportFileName($targetImport, $targetFileDir))) {
                $this->error("$lineId: import file $import not found");
                return false;
            }
        } else {
            $this->debug("$lineId: import $fullPath as $import", OutputInterface::VERBOSITY_DEBUG);
            if ($discover) {
                if (!$this->discoverLess($fullPath, $vars)) {
                    return false;
                }
            } else {
                if (!$this->processFile($fullPath, $vars)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Find import file
     *
     * @param string $filename Relative file name
     * @param string $baseDir  Base directory
     *
     * @return ?string
     */
    protected function resolveImportFileName(string $filename, string $baseDir): ?string
    {
        $allDirs = [
            $baseDir,
            ...$this->includePaths,
        ];
        $filename = preg_replace('/\.(less|scss)$/', '', $filename);
        foreach (['less', 'scss'] as $extension) {
            foreach ($allDirs as $dir) {
                // full path
                $fullPath = "$dir/$filename.$extension";
                if (!$this->isReadableFile($fullPath)) {
                    // reference import
                    $fullPath = dirname($fullPath) . '/_' . basename($fullPath);
                }
                if ($this->isReadableFile($fullPath)) {
                    return $fullPath;
                }
            }
        }
        return null;
    }

    /**
     * Replace variables that are defined later with their last values
     *
     * @param string $lineId Line identifier for logging
     * @param string $line   Line
     * @param array  $vars   Currently defined variables
     *
     * @return ?array Array of required variables and their values, or null on error
     */
    protected function checkVariables(string $lineId, string $line, array $vars): ?array
    {
        $required = [];
        preg_match_all('/\$(' . static::VARIABLE_CHARS . '+)(?!.*:)\\b/', $line, $allMatches);
        foreach ($allMatches[1] ?? [] as $var) {
            $lessVal = $this->allLessVars[$var] ?? null;
            if (
                isset($vars[$var])
                && null !== $lessVal
                && $vars[$var]['value'] === $this->processSubstitutions('', $lessVal)
            ) {
                // Previous definition contains the correct value:
                $this->debug("$lineId: $var ok", OutputInterface::VERBOSITY_VERY_VERBOSE);
                continue;
            }
            if (null === $lessVal) {
                $this->warning("$lineId: Value for variable `$var` not found (line: $line)");
                continue;
            }
            // Use last defined value:

            $this->debug("$lineId: Need `$lessVal` for $var (have `" . ($vars[$var]['value'] ?? '[nothing]') . '`)');
            $required[] = [
                'var' => $var,
                'value' => $lessVal,
            ];
        }
        return $required;
    }

    /**
     * Resolve requirements for variables that depend on other variables
     *
     * @param array $vars      Variables to resolve
     * @param array $knownVars Vars that are already available
     *
     * @return array
     */
    protected function resolveVariableDependencies(array $vars, array $knownVars): array
    {
        $result = $vars;
        foreach ($vars as $current) {
            $var = $current['var'];
            $varDefinition = $current['value'];
            $loop = 0;
            while (preg_match('/[@\$]\{?(' . static::VARIABLE_CHARS . '+)/', $varDefinition, $matches)) {
                $requiredVar = $matches[1];
                if (in_array($requiredVar, $knownVars)) {
                    $this->debug(
                        "Existing definition found for '$requiredVar' required by `$var: $varDefinition`",
                        OutputInterface::VERBOSITY_DEBUG
                    );
                    continue;
                }
                if ($requiredVarValue = $this->allLessVars[$requiredVar] ?? null) {
                    $this->debug("`$var: $varDefinition` requires `$requiredVar: $requiredVarValue`");
                    $result[] = [
                        'var' => $requiredVar,
                        'value' => $requiredVarValue,
                    ];
                    $varDefinition = $requiredVarValue;
                } else {
                    $this->warning(
                        "Could not resolve dependency for variable `$var`; definition missing for `$requiredVar`"
                    );
                    break;
                }
                if (++$loop >= 10) {
                    $this->warning("Value definition loop detected ($var -> $requiredVar)");
                    break;
                }
            }
        }
        return $result;
    }

    /**
     * Get block level (depth) change
     *
     * @param string $line Line
     *
     * @return int
     */
    protected function getBlockLevelChange(string $line): int
    {
        $level = 0;
        foreach (str_split($line) as $ch) {
            if ('{' === $ch) {
                ++$level;
            } elseif ('}' === $ch) {
                --$level;
            }
        }
        return $level;
    }

    /**
     * Update a file in the all files collection
     *
     * @param string $filename File name
     * @param array  $values   Values to set
     *
     * @return void;
     */
    protected function updateFileCollection(string $filename, array $values): void
    {
        $barename = preg_replace('/\.(less|scss)$/', '', $filename);
        if (null === ($oldValues = $this->allFiles[$barename] ?? null)) {
            $oldValues = [
                'requiredVars' => [],
            ];
            $values['index'] = count($this->allFiles);
        }
        if (!isset($oldValues['lines']) && !isset($values['lines'])) {
            // Read in any existing file:
            if (file_exists($filename)) {
                if (!$this->isReadableFile($filename)) {
                    throw new \Exception("$filename is not readable");
                }
                $values['lines'] = file($filename, FILE_IGNORE_NEW_LINES);
            }
        }
        // Merge requiredVars in case the file is imported multiple times
        // (we may end up with duplicates, but it's difficult to dedup without affecting order, so keep it that way):
        $values['requiredVars'] = array_merge(
            $oldValues['requiredVars'] ?? [],
            $values['requiredVars'] ?? []
        );
        $this->allFiles[$barename] = array_merge($oldValues, $values);
    }

    /**
     * Write target files
     *
     * @return bool
     */
    protected function writeTargetFiles(): bool
    {
        // If we have a variables file, collect all variables needed by later files and add them:
        if ($this->variablesFile) {
            $variablesFileIndex = $this->allFiles[$this->variablesFile]['index'] ?? PHP_INT_MAX;

            $allRequiredVars = [];
            foreach ($this->allFiles as $filename => &$fileSpec) {
                // Check if the file is included before the variables file (if so, we must add the variables in
                // that file):
                if ($fileSpec['index'] < $variablesFileIndex) {
                    continue;
                }
                array_push($allRequiredVars, ...$fileSpec['requiredVars']);
                $fileSpec['requiredVars'] = [];
            }
            unset($fileSpec);

            $this->updateFileCollection(
                $this->variablesFile,
                [
                    'requiredVars' => $allRequiredVars,
                ]
            );
            $this->debug(count($allRequiredVars) . " variables added to $this->variablesFile");
        }

        foreach ($this->allFiles as $filename => $fileSpec) {
            $fullPath = $this->getTargetFilename($filename);
            if (!$this->isInTargetDir($fullPath)) {
                continue;
            }
            if (!$this->ensureTargetDirectory($fullPath)) {
                return false;
            }

            $lines = $fileSpec['lines'] ?? [];

            // Add !default to existing variables (unless excluded):
            $addDefault = true;
            foreach ($this->noDefaultFiles as $noDefaultPattern) {
                if (fnmatch($noDefaultPattern, $fullPath)) {
                    $addDefault = false;
                }
            }
            if ($addDefault) {
                foreach ($lines as &$line) {
                    $line = preg_replace('/^(\$.+):([^;]+);/', '$1:$2 !default;', $line);
                }
                unset($line);
            }

            // Prepend required variables:
            if ($fileSpec['requiredVars']) {
                $requiredVars = $this->resolveVariableDependencies($fileSpec['requiredVars'], $fileSpec['vars'] ?? []);
                $linesToAdd = ['// The following variables were automatically added in SCSS conversion'];
                $addedVars = [];
                foreach (array_reverse($requiredVars) as $current) {
                    $var = $current['var'];
                    if (!in_array($var, $addedVars)) {
                        $linesToAdd[] = $this->processSubstitutions('', "@$var: $current[value];");
                        $addedVars[] = $var;
                    }
                }

                // Prepend new definitions:
                $linesToAdd[] = '';
                array_unshift($lines, ...$linesToAdd);
            }
            // Write the updated file:
            if (false === file_put_contents($fullPath, implode(PHP_EOL, $lines) . PHP_EOL)) {
                $this->error("Could not write file $fullPath");
            }
            $this->debug("Created $fullPath");
        }

        if ($this->enableScss) {
            $styleIni = <<<EOT
                [General]
                mode = scss

                EOT;
            $iniFile = $this->targetDir . '/style.ini';
            if (false === file_put_contents($iniFile, $styleIni)) {
                $this->error("Could not write file $iniFile");
            }
        }

        return true;
    }

    /**
     * Get target file name
     *
     * @param string $filename File name
     *
     * @return string
     */
    protected function getTargetFilename(string $filename): string
    {
        $fullPath = str_replace($this->sourceDir, $this->targetDir, $filename);
        if (!str_ends_with($fullPath, '.scss')) {
            $fullPath .= '.scss';
        }
        return $fullPath;
    }

    /**
     * Ensure that the directory for the given file exists
     *
     * @param string $filename File name
     *
     * @return bool
     */
    protected function ensureTargetDirectory(string $filename): bool
    {
        $dirParts = explode(DIRECTORY_SEPARATOR, dirname($filename));
        // Create the directory recursively for the parts that don't exist:
        for ($i = 1; $i <= count($dirParts); $i++) {
            $path = implode(DIRECTORY_SEPARATOR, array_slice($dirParts, 0, $i));
            // An absolute path has an empty element at the beginning:
            if ('' === $path) {
                continue;
            }
            if (!is_dir($path)) {
                if (!mkdir($path)) {
                    $this->error("Could not create directory $path");
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * Process string substitutions
     *
     * @param string $filename File name (or empty string when converting variables)
     * @param string $contents File contents
     *
     * @return string
     */
    protected function processSubstitutions(string $filename, string $contents): string
    {
        if ($filename) {
            $this->debug("$filename: start processing substitutions", OutputInterface::VERBOSITY_DEBUG);
        } else {
            $this->debug("Start processing substitutions for '$contents'", OutputInterface::VERBOSITY_DEBUG);
        }
        foreach ($this->substitutions as $i => $substitution) {
            if ($filename) {
                $this->debug("$filename: processing substitution $i", OutputInterface::VERBOSITY_DEBUG);
            } else {
                $this->debug("Processing substitution $i for '$contents'", OutputInterface::VERBOSITY_DEBUG);
            }
            if (str_starts_with($substitution['pattern'], '/')) {
                // Regexp
                if (is_string($substitution['replacement'])) {
                    $contents = preg_replace($substitution['pattern'], $substitution['replacement'], $contents);
                } else {
                    $contents = preg_replace_callback(
                        $substitution['pattern'],
                        $substitution['replacement'],
                        $contents
                    );
                }
                if (null === $contents) {
                    throw new \Exception(
                        "Failed to process regexp substitution $i: " . $substitution['pattern']
                        . ': ' . preg_last_error_msg()
                    );
                }
            } else {
                // String
                $contents = str_replace($substitution['pattern'], $substitution['replacement'], $contents);
            }
        }

        if ($filename) {
            $this->debug("$filename: done processing substitutions", OutputInterface::VERBOSITY_DEBUG);
        } else {
            $this->debug("Done processing substitutions for '$contents'", OutputInterface::VERBOSITY_DEBUG);
        }

        return $contents;
    }

    /**
     * Get substitutions
     *
     * @return array;
     */
    protected function getSubstitutions(): array
    {
        if ($localConfigFile = $this->pathResolver->getLocalConfigPath('lessToScss.config.php')) {
            $this->debug("Using local config file $localConfigFile", OutputInterface::VERBOSITY_DEBUG);
            $config = include $localConfigFile;
        } else {
            $configFile = dirname(__FILE__) . '/../../../../config/lessToScss.config.php';
            $this->debug("Using shared config file $configFile", OutputInterface::VERBOSITY_DEBUG);
            $config = include $configFile;
        }
        return $config['substitutions'];
    }

    /**
     * Output a debug message
     *
     * @param string $msg       Message
     * @param int    $verbosity Verbosity level
     *
     * @return void
     */
    protected function debug(string $msg, int $verbosity = OutputInterface::VERBOSITY_VERBOSE): void
    {
        $this->output->writeln($msg, $verbosity);
    }

    /**
     * Output an error message
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function error(string $msg): void
    {
        if ($this->output) {
            $this->output->writeln('<error>' . OutputFormatter::escape($msg) . '</error>');
        }
    }

    /**
     * Output a warning message
     *
     * @param string $msg Message
     *
     * @return void
     */
    protected function warning(string $msg): void
    {
        if ($this->output) {
            $this->output->writeln('<comment>' . OutputFormatter::escape($msg) . '</comment>');
        }
    }

    /**
     * Check if file name points to a readable file
     *
     * @param string $filename File name
     *
     * @return bool
     */
    protected function isReadableFile(string $filename): bool
    {
        return file_exists($filename) && (is_file($filename) || is_link($filename));
    }

    /**
     * Check for files newer than the timestamp file in the target directory
     *
     * @param string $timestampFile Timestamp file path
     * @param string $mainFile      Main file being processed
     *
     * @return bool
     */
    protected function filesNewerThanTimestampFile(string $timestampFile, string $mainFile): bool
    {
        $targetTimestampFile = $this->targetDir . "/$timestampFile";
        if (!file_exists($targetTimestampFile) || !($timestamp = filemtime($targetTimestampFile))) {
            return false;
        }
        $targetMainFile = $this->targetDir . '/' . preg_replace('/\.less$/', '', basename($mainFile)) . '.scss';
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->targetDir));
        foreach ($it as $file => $info) {
            if ($file === $targetMainFile || !str_ends_with($file, '.scss')) {
                // Don't care about target main file, it's centrally managed:
                continue;
            }
            if ($info->getMTime() > $timestamp) {
                return true;
            }
        }
        return false;
    }
}
