<?php

/**
 * Every runtime path that reads share rows goes through ShareRowJudge, which
 * asks the pending quarantine scan about each row, or is listed below with
 * the reason it needs no judge. This test tokenizes lib/ and reports:
 *
 * - every call of a share-row finder: each public read method of
 *   ShareTargetMapper, BulkGrantShareTargetMapper and
 *   QuarantinedShareTargetMapper, found by reflection;
 * - every string naming a finder, so a callable (`[$rows, 'findById']`,
 *   call_user_func) or a dynamic call is reported too;
 * - every place naming the share-targets table outside the three mappers
 *   and the migrations: quoted, in raw `*PREFIX*` SQL, or through a
 *   constant that holds the name, also as part of a concatenation or as an
 *   alias of another such constant. Comments and log messages do not count.
 *   Not caught: a name split across concatenated literals, or held in a
 *   property or variable.
 *
 * It fails closed. A finder name no other class in lib/ declares is reported
 * on any receiver. A name other classes share (findById and the like, see
 * SHARED_NAMES) is reported unless its receiver is proven to be another
 * class: `$this`, a variable or a `$this->` property whose every declaration
 * in the file (native type, `@var`/`@param`/`@property`, assignment, copy
 * of another variable or property) resolves to a class unrelated to the
 * share mappers, or a container lookup or `new` of such a class. Properties
 * and variables are kept apart: a parameter or local never types a property
 * of the same name. A getter, an inherited, undeclared or untyped property,
 * a loop or `global` variable, an untyped or by-reference parameter, a
 * variable passed to a by-reference parameter of a function the file
 * declares, or anything else it cannot follow is reported.
 *
 * The allowlists are per call site: a file, a finder and the number of calls
 * of it in that file, so a new call in an allowed file fails as well. An
 * entry that no longer matches fails too, so the lists cannot rot.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Architecture
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Architecture;

use OCA\Keepiq\Db\BulkGrantShareTargetMapper;
use OCA\Keepiq\Db\QuarantinedShareTargetMapper;
use OCA\Keepiq\Db\ShareTargetMapper;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use Throwable;

/**
 * Source-level guard on the share-row finders.
 *
 * @spec openspec/specs/user-sharing/spec.md#requirement-runtime-paths-follow-only-judged-share-rows
 */
class ShareRowLookupsAreJudgedTest extends TestCase {

	/**
	 * The mappers over the share-targets table. Every public method one of
	 * them declares (itself or through a trait) is a finder, unless its name
	 * marks it as a write.
	 *
	 * @var list<class-string>
	 */
	private const MAPPERS = [
		ShareTargetMapper::class,
		BulkGrantShareTargetMapper::class,
		QuarantinedShareTargetMapper::class,
	];

	/**
	 * Public mapper methods that write rows rather than return them.
	 *
	 * @var string
	 */
	private const WRITER_PATTERN = '/^(?:__construct|delete|insert|update)/i';

	/**
	 * Finder names another class in lib/ declares as well, so a call of them
	 * is reported only when its receiver is not proven to be that other
	 * class. Every other finder name is reported on any receiver.
	 *
	 * @var array<string,string>
	 */
	private const SHARED_NAMES = [
		'findById' => 'most mappers in lib/Db/ and several services declare one',
		'findBySourceSecret' => 'FederatedShareMapper and AttachmentMapper',
		'findByTeamFolder' => 'TeamFolderMemberMapper',
		'findEndingBetween' => 'the ExpiringGrantQueries trait, also used by GroupShareMapper and TeamFolderMemberMapper',
	];

	/**
	 * Type names that can never receive a method call.
	 *
	 * @var list<string>
	 */
	private const SCALAR_TYPES = [
		'null', 'false', 'true', 'void', 'never', 'int', 'float', 'string', 'bool', 'array', 'iterable', 'list',
		'non-empty-array', 'non-empty-list', 'non-empty-string', 'class-string', 'positive-int', 'resource', 'callable',
	];

	/**
	 * Tokens that name a class.
	 *
	 * @var list<int>
	 */
	private const NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE];

	/**
	 * Tokens that may stand before a typed parameter or property.
	 *
	 * @var list<int>
	 */
	private const MODIFIERS = [T_PRIVATE, T_PROTECTED, T_PUBLIC, T_READONLY, T_VAR, T_STATIC];

	/**
	 * The instance-call operators.
	 *
	 * @var list<int>
	 */
	private const OBJECT_OPERATORS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR];

	/**
	 * The share-targets table, as a query names it: quoted, in raw SQL after
	 * `*PREFIX*`, or anywhere else as a whole word. Comments do not count.
	 *
	 * @var string
	 */
	private const TABLE_PATTERN = '/\b(?:keepiq_)?share_targets\b/';

	/**
	 * The calls allowed outside ShareRowJudge: file (relative to lib/) =>
	 * finder => [number of calls, why they need no judge].
	 *
	 * @var array<string,array<string,array{0:int,1:string}>>
	 */
	private const ALLOWED = [
		'Service/ShareRowJudge.php' => [
			'findByRecipientSecret' => [1, 'the judged layer itself'],
			'findBySourceSecretAndTargetUser' => [1, 'the judged layer itself'],
			'findBySourceSecret' => [1, 'the judged layer itself'],
			'findByTargetUser' => [1, 'the judged layer itself'],
		],
		'Service/ShareQuarantineService.php' => [
			'findBySourceSecretAndTargetUser' => [1, 'admin release: refuses while a live row for the pair exists'],
			'findBySourceSecret' => [1, 'admin release: refuses when the copy is itself a live share source'],
			'findQuarantined' => [1, 'list(): the admin quarantine list; held-back rows are what it shows'],
			'findQuarantinedById' => [1, 'loadQuarantined(): the admin release or drop of one held-back row'],
		],
		'Service/KnownShareRecipientExemption.php' => [
			'hasDirectShareBetween' => [
				1,
				'key-proof exemption: yes/no on a live direct row created by the caller; follows no row; quarantined rows excluded in SQL',
			],
		],
		'Listener/MarksCompromisedSecrets.php' => [
			'findById' => [
				1,
				'trait, so $secretMapper is the using class\'s SecretMapper; loads the source of a row ShareRowJudge::forCopy() trusted',
			],
		],
		'Service/ShareRequestService.php' => [
			'findBySourceSecretAndTargetUser' => [2, 'existence check before a share request; reads no copy and follows no row'],
		],
		'Service/TeamFolderShareService.php' => [
			'findBySourceSecretAndTargetUser' => [2, 'missingPairs() and fan-out idempotency: an existing row only skips a pair or a mint'],
			'findByTeamFolder' => [2, 'confirmers() lists user ids for the folder dialog; folderRows() hands its rows to the judge'],
			'findByTeamFolderAndTargetUser' => [1, 'folderRows() hands its rows to the judge'],
		],
		'Service/OfflineManifestService.php' => [
			'findByTargetUser' => [
				1,
				'sourceVersions(): maps the caller\'s received copies to their source\'s updatedAt; reads no source content or key',
			],
		],
		'Service/DashboardSummaryService.php' => [
			'findByTargetUser' => [1, 'counts the caller\'s received rows; follows none'],
		],
		'Service/GdprService.php' => [
			'findBySourceSecret' => [1, 'GDPR export of shares given: ids and dates of the subject\'s rows; follows none to a copy'],
			'findByTargetUser' => [1, 'GDPR export of shares received: ids and dates of the subject\'s rows; follows none to a copy'],
			'findQuarantinedBySourceSecret' => [1, 'GDPR export of shares given: held-back rows are listed and flagged quarantined'],
			'findQuarantinedByTargetUser' => [1, 'GDPR export of shares received: held-back rows are listed and flagged quarantined'],
		],
		'Service/DirectShareRegistrar.php' => [
			'findBySourceSecretAndTargetUser' => [1, 'register-batch idempotency: an existing row only answers `exists`'],
		],
		'Service/ShareService.php' => [
			'findBySourceSecret' => [2, 'lists rows to the owner, a delegate or a write-grade member; every write goes through share-save'],
			'findById' => [1, 'restriction change of one row: ShareQuarantineScanner::assertNotHeldBack() judges it first'],
		],
		'Service/AccountShareCleanupService.php' => [
			'findBySourceSecret' => [1, 'account deletion: each row is judged (isHeldByTarget, heldBack) before its copy is touched'],
		],
		'Service/ShareRevocationService.php' => [
			'findBySourceSecret' => [2, 'revoke-all and cascade: each row is judged (refuseUnjudged/heldBack) before its copy is deleted'],
			'findById' => [1, 'revoke of one row: ShareQuarantineScanner::assertNotHeldBack() judges it first'],
			'findQuarantinedById' => [1, 'refuseQuarantined(): tells the source\'s owner the row is held back (409); deletes nothing'],
		],
		'Service/ShareRestrictionResolver.php' => [
			'findBySourceSecret' => [1, 'resolveTargets() judges each row (heldBack) before its copy is written'],
			'findPage' => [1, 'the full resolve once the scan completes: resolveTargets() judges each row'],
		],
		'Service/ShareExpiryService.php' => [
			'findById' => [1, 'reads an ended row again; it is revoked through ShareRevocationService, which judges it'],
		],
		'Service/ExpiredGrantRemover.php' => [
			'findEndingBetween' => [1, 'each ended row is revoked through ShareRevocationService::revokeShare(), which judges it'],
		],
		'Service/GroupShareService.php' => [
			'findByGroupShare' => [4, 'group revoke, member leave and restriction change judge each row (refuseUnjudged/heldBack) first'],
		],
		'Repair/SeedDevelopmentShares.php' => [
			'findById' => [2, 'development seed: checks whether its own fixed row already exists'],
		],
	];

	/**
	 * Files that may name the share-targets table, with the number of times
	 * and why.
	 *
	 * @var array<string,array{0:int,1:string}>
	 */
	private const TABLE_ALLOWED = [
		'Service/ShareQuarantineScanner.php' => [
			5,
			'the scan itself judges every row, quarantined or not: the TABLE constant and its four uses, three reads and the '
				.'update that marks a row quarantined',
		],
		'Service/ComplianceReportService.php' => [1, 'counts live rows for the compliance report; follows none'],
		'Backup/BackupTableRegistry.php' => [1, 'backup and restore copy the table whole; a restore reopens the scan'],
		'Backup/RestoreService.php' => [
			2,
			'the table list (BackupTableRegistry::TABLES) the restore replaces whole, and the row count of the archive file '
				.'tables/share_targets.jsonl, read to reopen the scan; follows no row',
		],
		'Backup/ArchiveReader.php' => [1, 'verify(): iterates the table list (BackupTableRegistry::TABLES) to check an archive; reads no live row'],
		'Backup/ArchiveWriter.php' => [1, 'iterates the table list (BackupTableRegistry::TABLES) to copy every table whole into a backup'],
		'Backup/SchemaFingerprint.php' => [1, 'hashes the table list (BackupTableRegistry::TABLES); reads no row'],
		'Backup/TableStore.php' => [1, 'replaceAll(): iterates the table list (BackupTableRegistry::TABLES) to replace every table whole; the restore reopens the scan'],
	];

	/**
	 * Absolute path to lib/.
	 *
	 * @return string
	 */
	private function libPath(): string {
		return dirname(__DIR__, 3).'/lib';
	}//end libPath()

	/**
	 * Every PHP file under lib/, relative to it, with its source.
	 *
	 * @return array<string,string>
	 */
	private function libSources(): array {
		$sources = [];
		$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->libPath(), RecursiveDirectoryIterator::SKIP_DOTS));
		foreach ($files as $file) {
			if ($file->isFile() === true && $file->getExtension() === 'php') {
				$sources[substr($file->getPathname(), strlen($this->libPath()) + 1)] = (string)file_get_contents($file->getPathname());
			}
		}

		ksort($sources);

		return $sources;
	}//end libSources()

	/**
	 * The finders: every public method a mapper declares (itself or through
	 * a trait) that is not a write, as "lower-case name => name".
	 *
	 * @return array<string,string>
	 */
	private function finders(): array {
		$finders = [];
		foreach (self::MAPPERS as $class) {
			foreach ((new ReflectionClass($class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
				if ($method->getDeclaringClass()->getName() === $class && preg_match(self::WRITER_PATTERN, $method->getName()) === 0) {
					$finders[strtolower($method->getName())] = $method->getName();
				}
			}
		}

		ksort($finders);

		return $finders;
	}//end finders()

	/**
	 * The mapper files themselves, relative to lib/ (the autoloader may load
	 * them from another checkout, so only the part after lib/ is kept).
	 *
	 * @return list<string>
	 */
	private function mapperFiles(): array {
		return array_map(
			static fn (string $class): string => (string)preg_replace('#^.*/lib/#', '', (string)(new ReflectionClass($class))->getFileName()),
			self::MAPPERS
		);
	}//end mapperFiles()

	/**
	 * The finder names another file in lib/ declares a method of.
	 *
	 * @return list<string>
	 */
	private function namesDeclaredElsewhere(): array {
		$finders = $this->finders();
		$names = [];
		foreach ($this->libSources() as $file => $source) {
			if (in_array($file, $this->mapperFiles(), true) === true) {
				continue;
			}

			preg_match_all('/\bfunction\s+(\w+)\s*\(/', $source, $declared);
			foreach ($declared[1] as $name) {
				if (isset($finders[strtolower($name)]) === true) {
					$names[] = $finders[strtolower($name)];
				}
			}
		}

		$names = array_values(array_unique($names));
		sort($names);

		return $names;
	}//end namesDeclaredElsewhere()

	/**
	 * Every raw finder call in lib/ outside the mappers, as "file => finder => lines".
	 *
	 * @return array<string,array<string,list<int>>>
	 */
	private function rawCalls(): array {
		$calls = [];
		foreach ($this->libSources() as $file => $source) {
			if (in_array($file, $this->mapperFiles(), true) === true) {
				continue;
			}

			$found = $this->callsIn(source: $source);
			if ($found !== []) {
				$calls[$file] = $found;
			}
		}

		return $calls;
	}//end rawCalls()

	/**
	 * Every place naming the share-targets table in lib/ outside the three
	 * mappers and the migrations, directly or through a constant, as
	 * "file => lines".
	 *
	 * @return array<string,list<int>>
	 */
	private function tableReads(): array {
		$sources = $this->libSources();
		$constants = [];
		foreach ($sources as $source) {
			$tokens = $this->significant(source: $source);
			$class = $this->contextOf(source: $source, tokens: $tokens)['class'];
			$names = $this->tableConstantsIn(tokens: $tokens);
			if ($class !== null && $names !== []) {
				$short = strtolower(substr((string)strrchr('\\'.$class, '\\'), 1));
				$constants[$short] = array_merge(($constants[$short] ?? []), $names);
			}
		}

		$reads = [];
		foreach ($sources as $file => $source) {
			if ($this->mayNameTheTable(file: $file) === true) {
				continue;
			}

			$lines = $this->tableLinesIn(source: $source, constants: $constants);
			if ($lines !== []) {
				$reads[$file] = $lines;
			}
		}

		return $reads;
	}//end tableReads()

	/**
	 * Whether a file (relative to lib/) may name the share-targets table
	 * freely: the three share mappers and the migrations only.
	 *
	 * @param string $file The file, relative to lib/
	 *
	 * @return bool
	 */
	private function mayNameTheTable(string $file): bool {
		return in_array($file, $this->mapperFiles(), true) === true || str_starts_with($file, 'Migration/') === true;
	}//end mayNameTheTable()

	/**
	 * The tokens of a source that are not whitespace or comments.
	 *
	 * @param string $source The source
	 *
	 * @return list<PhpToken>
	 */
	private function significant(string $source): array {
		return array_values(array_filter(PhpToken::tokenize($source), static fn (PhpToken $token): bool => $token->isIgnorable() === false));
	}//end significant()

	/**
	 * The constants a file declares whose value names the share-targets
	 * table: a literal holding the name (also as one part of a concatenation)
	 * or another such constant of the same class (`const ALIAS = self::TABLE`).
	 *
	 * @param list<PhpToken> $tokens The significant tokens
	 *
	 * @return list<string>
	 */
	private function tableConstantsIn(array $tokens): array {
		$values = [];
		foreach ($tokens as $i => $token) {
			if ($token->is(T_CONST) === false) {
				continue;
			}

			// One `const` may declare several: NAME = value, NAME = value;
			$name = null;
			$depth = 0;
			for ($j = $i + 1; ($tokens[$j] ?? null) !== null && $tokens[$j]->text !== ';'; $j++) {
				$text = $tokens[$j]->text;
				if (in_array($text, ['(', '['], true) === true) {
					$depth++;
				} elseif (in_array($text, [')', ']'], true) === true) {
					$depth--;
				} elseif ($depth === 0 && $text === '=' && $tokens[$j - 1]->is(T_STRING) === true) {
					$name = $tokens[$j - 1]->text;
					$values[$name] = [];
				} elseif ($depth === 0 && $text === ',') {
					$name = null;
				} elseif ($name !== null) {
					$values[$name][] = $j;
				}
			}
		}

		$names = [];
		do {
			$found = false;
			foreach ($values as $name => $indexes) {
				if (in_array($name, $names, true) === true) {
					continue;
				}

				foreach ($indexes as $j) {
					$literal = ($tokens[$j]->is(T_CONSTANT_ENCAPSED_STRING) === true && preg_match(self::TABLE_PATTERN, $tokens[$j]->text) === 1);
					$alias = ($tokens[$j]->is(T_STRING) === true && in_array($tokens[$j]->text, $names, true) === true
						&& ($tokens[$j - 1] ?? null)?->is(T_DOUBLE_COLON) === true);
					if ($literal === true || $alias === true) {
						$names[] = $name;
						$found = true;
						break;
					}
				}
			}
		} while ($found === true);

		return $names;
	}//end tableConstantsIn()

	/**
	 * The lines of one file that name the share-targets table: the name
	 * itself outside comments, and every read of a constant that holds it
	 * (`self::`/`static::` of the file's own, or `Class::` of one another
	 * file declares).
	 *
	 * @param string                    $source    The file's source
	 * @param array<string,list<string>> $constants Lower-case short class name => its table constants
	 *
	 * @return list<int>
	 */
	private function tableLinesIn(string $source, array $constants=[]): array {
		$lines = $this->linesMatching(pattern: self::TABLE_PATTERN, source: $source);
		$tokens = $this->significant(source: $source);
		$own = $this->tableConstantsIn(tokens: $tokens);
		$class = $this->contextOf(source: $source, tokens: $tokens)['class'];
		$self = strtolower(substr((string)strrchr('\\'.(string)$class, '\\'), 1));
		foreach ($tokens as $i => $token) {
			if ($token->is(T_STRING) === false || ($tokens[$i - 1] ?? null)?->is(T_DOUBLE_COLON) !== true
				|| ($tokens[$i + 1] ?? null)?->text === '(' || ($tokens[$i - 2] ?? null) === null
			) {
				continue;
			}

			$receiver = strtolower(substr((string)strrchr('\\'.$tokens[$i - 2]->text, '\\'), 1));
			$names = ($constants[$receiver] ?? []);
			if (in_array($receiver, ['self', 'static', '$this', $self], true) === true) {
				$names = array_merge($names, $own);
			}

			if (in_array($token->text, $names, true) === true) {
				$lines[] = $token->line;
			}
		}

		sort($lines);

		return $lines;
	}//end tableLinesIn()

	/**
	 * The raw finder calls in one file, and the strings naming a finder.
	 *
	 * @param string $source The file's source
	 *
	 * @return array<string,list<int>> Finder => line numbers
	 */
	private function callsIn(string $source): array {
		$finders = $this->finders();
		$all = PhpToken::tokenize($source);
		$tokens = array_values(array_filter($all, static fn (PhpToken $token): bool => $token->isIgnorable() === false));
		$context = $this->contextOf(source: $source, tokens: $tokens);
		$types = $this->receiverTypes(tokens: $tokens, all: $all, context: $context);

		$calls = [];
		foreach ($tokens as $i => $token) {
			$finder = ($finders[strtolower(trim($token->text, '\'"'))] ?? null);
			if ($finder === null) {
				continue;
			}

			if ($token->is(T_CONSTANT_ENCAPSED_STRING) === true) {
				// A callable or a dynamic call: its receiver is not followed, so it is always reported.
				$calls[$finder][] = $token->line;
				continue;
			}

			$operator = ($tokens[$i - 1] ?? null);
			if ($token->is(T_STRING) === false || ($tokens[$i + 1] ?? null)?->text !== '('
				|| $operator === null || $operator->is([...self::OBJECT_OPERATORS, T_DOUBLE_COLON]) === false
			) {
				continue;
			}

			if (isset(self::SHARED_NAMES[$finder]) === false
				|| $this->receiverOf(tokens: $tokens, operator: $i - 1, context: $context, types: $types) !== 'other'
			) {
				$calls[$finder][] = $token->line;
			}
		}//end foreach

		return $calls;
	}//end callsIn()

	/**
	 * The namespace, the imports (also grouped and aliased) and the first
	 * class a file declares.
	 *
	 * @param string         $source The file's source
	 * @param list<PhpToken> $tokens Its significant tokens
	 *
	 * @return array{namespace:string,uses:array<string,string>,class:?string}
	 */
	private function contextOf(string $source, array $tokens): array {
		$context = ['namespace' => '', 'uses' => [], 'class' => null];
		foreach ($tokens as $i => $token) {
			if ($token->is(T_NAMESPACE) === true && ($tokens[$i + 1] ?? null)?->is(self::NAME_TOKENS) === true) {
				$context['namespace'] = $tokens[$i + 1]->text;
				break;
			}
		}

		$imports = [];
		preg_match_all('/^\s*use\s+\\\\?([\w\\\\]+?)\\\\\{([^}]*)\}\s*;/m', $source, $groups, PREG_SET_ORDER);
		foreach ($groups as [, $prefix, $items]) {
			foreach (explode(',', $items) as $item) {
				$imports[] = $prefix.'\\'.trim($item);
			}
		}

		preg_match_all('/^\s*use\s+(?!function\b|const\b)([\w\\\\][^;{(]*);/m', $source, $plain);
		foreach ($plain[1] as $list) {
			$imports = array_merge($imports, explode(',', $list));
		}

		foreach ($imports as $import) {
			if (preg_match('/^\s*\\\\?([\w\\\\]+)(?:\s+as\s+(\w+))?\s*$/i', $import, $parts) === 1) {
				$alias = ($parts[2] ?? '');
				if ($alias === '') {
					$alias = substr((string)strrchr('\\'.$parts[1], '\\'), 1);
				}

				$context['uses'][strtolower($alias)] = $parts[1];
			}
		}

		foreach ($tokens as $i => $token) {
			if ($token->is([T_CLASS, T_TRAIT, T_ENUM]) === true && ($tokens[$i - 1] ?? null)?->is([T_DOUBLE_COLON, T_NEW]) !== true
				&& ($tokens[$i + 1] ?? null)?->is(T_STRING) === true
			) {
				$context['class'] = ltrim($context['namespace'].'\\'.$tokens[$i + 1]->text, '\\');
				break;
			}
		}

		return $context;
	}//end contextOf()

	/**
	 * What every variable and `$this->` property name in a file may hold,
	 * from each declaration of it: a native type, an `@var`/`@param`/
	 * `@property` tag, an assignment (a copy of another name, `new`, a
	 * container lookup, or anything else as unknown), a foreach or a
	 * destructuring (unknown). Locals and properties share one name space,
	 * which can only add to what a name may hold.
	 *
	 * @param list<PhpToken>                                                 $tokens  The significant tokens
	 * @param list<PhpToken>                                                 $all     All tokens, for the doc comments
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context The file's context
	 *
	 * @return array<string,list<string>> Name => 'share', 'other', 'unknown', 'none' or '$copied-name'
	 */
	private function receiverTypes(array $tokens, array $all, array $context): array {
		$types = [];
		foreach ($this->passedByReference(tokens: $tokens) as $name) {
			$types[$name][] = 'unknown';
		}

		$count = count($tokens);
		for ($i = 0; $i < $count; $i++) {
			$token = $tokens[$i];
			if ($token->is(T_FOREACH) === true) {
				$close = $this->matching(tokens: $tokens, open: $i + 1);
				$as = false;
				for ($j = $i + 2; $j < $close; $j++) {
					$as = ($as || $tokens[$j]->is(T_AS));
					if ($as === true && $tokens[$j]->is(T_VARIABLE) === true) {
						$types[substr($tokens[$j]->text, 1)][] = 'unknown';
					}
				}

				continue;
			}

			if ($token->text === '=' && in_array($tokens[$i - 1]->text, [']', ')'], true) === true) {
				$open = $this->matching(tokens: $tokens, open: $i - 1);
				$before = ($tokens[$open - 1] ?? null);
				if ($before === null || $before->is(T_LIST) === true
					|| ($tokens[$i - 1]->text === ']' && $before->is([T_VARIABLE, T_STRING]) === false && in_array($before->text, [']', ')', '}'], true) === false)
				) {
					for ($j = $open; $j < $i; $j++) {
						if ($tokens[$j]->text === '$this' && ($tokens[$j + 2] ?? null)?->is(T_STRING) === true) {
							$types['->'.$tokens[$j + 2]->text][] = 'unknown';
						} elseif ($tokens[$j]->is(T_VARIABLE) === true) {
							$types[substr($tokens[$j]->text, 1)][] = 'unknown';
						}
					}
				}

				continue;
			}

			if ($token->is(T_GLOBAL) === true) {
				for ($j = $i + 1; $j < $count && $tokens[$j]->text !== ';'; $j++) {
					if ($tokens[$j]->is(T_VARIABLE) === true) {
						$types[substr($tokens[$j]->text, 1)][] = 'unknown';
					}
				}

				continue;
			}

			if ($token->is(T_VARIABLE) === false) {
				continue;
			}

			if ($token->text === '$this') {
				if (($tokens[$i + 1] ?? null)?->is(self::OBJECT_OPERATORS) === true && ($tokens[$i + 2] ?? null)?->is(T_STRING) === true
					&& ($tokens[$i + 3] ?? null)?->is(['=', T_COALESCE_EQUAL]) === true
				) {
					$types['->'.$tokens[$i + 2]->text][] = $this->assigned(tokens: $tokens, start: $i + 4, context: $context);
				}

				continue;
			}

			$name = substr($token->text, 1);
			if (($tokens[$i + 1] ?? null)?->is(['=', T_COALESCE_EQUAL]) === true && ($tokens[$i - 1] ?? null)?->is(self::OBJECT_OPERATORS) !== true) {
				$types[$name][] = $this->assigned(tokens: $tokens, start: $i + 2, context: $context);
			}

			// A property declaration types the property; a promoted parameter is
			// both. A plain parameter or local never types a property.
			$keys = [$name];
			if ($this->declaresProperty(tokens: $tokens, variable: $i) === true) {
				$keys = ['->'.$name];
				if ($this->isParameter(tokens: $tokens, variable: $i) === true) {
					$keys[] = $name;
				}
			}

			foreach ($this->declaredTypes(tokens: $tokens, variable: $i, context: $context) as $type) {
				foreach ($keys as $key) {
					$types[$key][] = $type;
				}
			}
		}//end for

		foreach ($all as $d => $doc) {
			if ($doc->is(T_DOC_COMMENT) === false) {
				continue;
			}

			$next = ($d + 1);
			while (($all[$next] ?? null)?->isIgnorable() === true) {
				$next++;
			}

			$onProperty = (($all[$next] ?? null)?->is(self::MODIFIERS) === true);
			preg_match_all('/@(var|param|property(?:-read|-write)?)\s+(\S+)(?:\s+&?\s*(?:\.\.\.)?\$(\w+))?/', $doc->text, $tags, PREG_SET_ORDER);
			foreach ($tags as $tag) {
				$property = (str_starts_with($tag[1], 'property') === true || ($tag[1] === 'var' && $onProperty === true));
				array_shift($tag);
				$name = ($tag[2] ?? '');
				if ($name === '') {
					$name = $this->nextVariable(all: $all, after: $d);
				}

				if ($name === null) {
					continue;
				}

				if ($property === true) {
					$name = '->'.$name;
				}

				$expression = $tag[1];
				do {
					$expression = preg_replace('/<[^<>]*>|\{[^{}]*\}/', '', (string)$expression, -1, $stripped);
				} while ($stripped > 0);

				foreach (preg_split('/[|&()]/', (string)$expression) as $part) {
					$part = ltrim(trim($part), '?');
					if (preg_match('/^\\\\?[\w\\\\]+$/', $part) === 1) {
						$types[$name][] = $this->classify(type: $part, context: $context);
					}
				}
			}
		}//end foreach

		return $types;
	}//end receiverTypes()

	/**
	 * The variables a file passes, as a bare argument, to a function or
	 * method it declares with a by-reference parameter: the callee can
	 * assign them anything.
	 *
	 * @param list<PhpToken> $tokens The significant tokens
	 *
	 * @return list<string>
	 */
	private function passedByReference(array $tokens): array {
		$functions = [];
		foreach ($tokens as $i => $token) {
			if ($token->is(T_FUNCTION) === false) {
				continue;
			}

			$at = ($i + 1);
			if (($tokens[$at] ?? null)?->text === '&') {
				$at++;
			}

			$name = ($tokens[$at] ?? null);
			$open = ($at + 1);
			if ($name?->is(T_STRING) !== true || ($tokens[$open] ?? null)?->text !== '(') {
				continue;
			}

			$close = $this->matching(tokens: $tokens, open: $open);
			for ($j = $open + 1; $j < $close; $j++) {
				if ($tokens[$j]->text === '&' && ($tokens[$j + 1]->is(T_VARIABLE) === true || $tokens[$j + 1]->is(T_ELLIPSIS) === true)) {
					$functions[strtolower($name->text)] = true;
				}
			}
		}

		$names = [];
		foreach ($tokens as $i => $token) {
			if ($token->is(T_STRING) === false || isset($functions[strtolower($token->text)]) === false
				|| ($tokens[$i + 1] ?? null)?->text !== '(' || ($tokens[$i - 1] ?? null)?->is(T_FUNCTION) === true
				|| ($tokens[$i - 1] ?? null)?->text === '&'
			) {
				continue;
			}

			$close = $this->matching(tokens: $tokens, open: $i + 1);
			for ($j = $i + 2; $j < $close; $j++) {
				if ($tokens[$j]->is(T_VARIABLE) === true && in_array($tokens[$j - 1]->text, ['(', ',', ':'], true) === true
					&& in_array($tokens[$j + 1]->text, [',', ')'], true) === true
				) {
					$names[] = substr($tokens[$j]->text, 1);
				}
			}
		}

		return $names;
	}//end passedByReference()

	/**
	 * The variable a nameless `@var` tag documents: the next one before the
	 * end of the declaration.
	 *
	 * @param list<PhpToken> $all   All tokens
	 * @param int            $after The doc comment's index
	 *
	 * @return string|null
	 */
	private function nextVariable(array $all, int $after): ?string {
		$count = count($all);
		for ($i = $after + 1; $i < $count; $i++) {
			if ($all[$i]->is(T_VARIABLE) === true) {
				return substr($all[$i]->text, 1);
			}

			if (in_array($all[$i]->text, [';', '{', '}', '('], true) === true) {
				return null;
			}
		}

		return null;
	}//end nextVariable()

	/**
	 * The native types declared right before a variable, when it is a
	 * parameter, a property or a caught exception. An untyped parameter and
	 * a by-reference one are unknown: a caller can pass, or the callee
	 * assign, anything.
	 *
	 * @param list<PhpToken>                                                 $tokens   The significant tokens
	 * @param int                                                            $variable The variable's index
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context  The file's context
	 *
	 * @return list<string>
	 */
	private function declaredTypes(array $tokens, int $variable, array $context): array {
		$j = ($variable - 1);
		$byReference = false;
		while ($j >= 0 && ($tokens[$j]->text === '&' || $tokens[$j]->is(T_ELLIPSIS) === true)) {
			$byReference = ($byReference || $tokens[$j]->text === '&');
			$j--;
		}

		if ($this->isParameter(tokens: $tokens, variable: $variable) === true
			&& ($byReference === true || $this->isTyped(tokens: $tokens, before: $j) === false)
		) {
			return ['unknown'];
		}

		$parts = [];
		while ($j >= 0
			&& ($tokens[$j]->is([...self::NAME_TOKENS, T_ARRAY, T_CALLABLE]) === true || in_array($tokens[$j]->text, ['?', '|'], true) === true)
		) {
			if ($tokens[$j]->text !== '?' && $tokens[$j]->text !== '|') {
				$parts[] = $tokens[$j]->text;
			}

			$j--;
		}

		$before = ($tokens[$j] ?? null);
		if ($parts === [] || $before === null || ($before->text !== '(' && $before->text !== ',' && $before->is(self::MODIFIERS) === false)) {
			return [];
		}

		return array_map(fn (string $part): string => $this->classify(type: $part, context: $context), $parts);
	}//end declaredTypes()

	/**
	 * Whether a variable is a property declaration (also a promoted
	 * constructor parameter): a modifier stands before it and its type.
	 *
	 * @param list<PhpToken> $tokens   The significant tokens
	 * @param int            $variable The variable's index
	 *
	 * @return bool
	 */
	private function declaresProperty(array $tokens, int $variable): bool {
		$j = ($variable - 1);
		while ($j >= 0 && ($tokens[$j]->is([...self::NAME_TOKENS, T_ARRAY, T_CALLABLE, T_ELLIPSIS]) === true
			|| in_array($tokens[$j]->text, ['&', '?', '|', '(', ')'], true) === true)
		) {
			if ($tokens[$j]->text === '(' && ($tokens[$j - 1] ?? null)?->is(self::MODIFIERS) !== true && ($tokens[$j - 1] ?? null)?->text !== '|') {
				break;
			}

			$j--;
		}

		return ($tokens[$j] ?? null)?->is(self::MODIFIERS) === true;
	}//end declaresProperty()

	/**
	 * Whether the token before a parameter's name (after any `&` and `...`)
	 * ends a type.
	 *
	 * @param list<PhpToken> $tokens The significant tokens
	 * @param int            $before The index of that token
	 *
	 * @return bool
	 */
	private function isTyped(array $tokens, int $before): bool {
		$token = ($tokens[$before] ?? null);

		return $token !== null && ($token->is([...self::NAME_TOKENS, T_ARRAY, T_CALLABLE, T_STATIC]) === true || $token->text === ')');
	}//end isTyped()

	/**
	 * Whether a variable stands at the top level of a function's, a
	 * closure's or an arrow function's parameter list.
	 *
	 * @param list<PhpToken> $tokens   The significant tokens
	 * @param int            $variable The variable's index
	 *
	 * @return bool
	 */
	private function isParameter(array $tokens, int $variable): bool {
		$depth = 0;
		for ($i = $variable - 1; $i >= 0; $i--) {
			$text = $tokens[$i]->text;
			if (in_array($text, [')', ']', '}'], true) === true) {
				$depth++;
			} elseif (in_array($text, ['(', '[', '{'], true) === true || $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
				if ($depth === 0) {
					break;
				}

				$depth--;
			} elseif ($depth === 0 && $text === ';') {
				return false;
			}
		}

		if ($i < 0 || $tokens[$i]->text !== '(') {
			return false;
		}

		$k = ($i - 1);
		if (($tokens[$k] ?? null)?->is(T_STRING) === true) {
			$k--;
		}

		if (($tokens[$k] ?? null)?->text === '&') {
			$k--;
		}

		return ($tokens[$k] ?? null)?->is([T_FUNCTION, T_FN]) === true;
	}//end isParameter()

	/**
	 * What the right-hand side of an assignment holds: a copy of another
	 * name, a `new` or a container lookup of a class, or unknown.
	 *
	 * @param list<PhpToken>                                                 $tokens  The significant tokens
	 * @param int                                                            $start   The first token after `=`
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context The file's context
	 *
	 * @return string
	 */
	private function assigned(array $tokens, int $start, array $context): string {
		$count = count($tokens);
		$end = $start;
		$depth = 0;
		for (; $end < $count; $end++) {
			$text = $tokens[$end]->text;
			if (in_array($text, ['(', '[', '{'], true) === true || $tokens[$end]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true) {
				$depth++;
			} elseif (in_array($text, [')', ']', '}'], true) === true) {
				if ($depth === 0) {
					break;
				}

				$depth--;
			} elseif ($depth === 0 && ($text === ';' || $text === ',')) {
				break;
			}
		}

		if (($tokens[$start] ?? null)?->text === '&') {
			$start++;
		}

		$rhs = array_slice($tokens, $start, $end - $start);
		$size = count($rhs);
		if ($size === 1 && $rhs[0]->is(T_VARIABLE) === true && $rhs[0]->text !== '$this') {
			return $rhs[0]->text;
		}

		// A literal (also a parameter default such as `= null`) holds no object.
		if (($size === 1 && ($rhs[0]->is([T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING]) === true
			|| in_array(strtolower($rhs[0]->text), ['null', 'true', 'false'], true) === true))
			|| ($size === 2 && $rhs[0]->text === '[' && $rhs[1]->text === ']')
		) {
			return 'none';
		}

		$property = ($size === 3 && $rhs[0]->text === '$this' && $rhs[1]->is(self::OBJECT_OPERATORS) === true && $rhs[2]->is(T_STRING) === true);
		$static = ($size === 3 && in_array(strtolower($rhs[0]->text), ['self', 'static'], true) === true
			&& $rhs[1]->is(T_DOUBLE_COLON) === true && $rhs[2]->is(T_VARIABLE) === true);
		if ($property === true || $static === true) {
			return '$->'.ltrim($rhs[2]->text, '$');
		}

		if ($size >= 2 && $rhs[0]->is(T_NEW) === true) {
			$simple = ($size === 2 || ($rhs[2]->text === '(' && $this->matching(tokens: $tokens, open: $start + 2) === $end - 1));
			if ($simple === true && $rhs[1]->is([...self::NAME_TOKENS, T_STATIC]) === true) {
				return $this->classify(type: $rhs[1]->text, context: $context);
			}

			return 'unknown';
		}

		if ($size > 0 && $rhs[$size - 1]->text === ')') {
			return $this->typeOfCall(tokens: $tokens, close: $end - 1, context: $context);
		}

		return 'unknown';
	}//end assigned()

	/**
	 * What the receiver of a finder call holds.
	 *
	 * @param list<PhpToken>                                                 $tokens   The significant tokens
	 * @param int                                                            $operator The index of `->`, `?->` or `::`
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context  The file's context
	 * @param array<string,list<string>>                                     $types    What each name may hold
	 *
	 * @return string 'share', 'other' or 'unknown'
	 */
	private function receiverOf(array $tokens, int $operator, array $context, array $types): string {
		$before = ($tokens[$operator - 1] ?? null);
		if ($before === null) {
			return 'unknown';
		}

		if ($tokens[$operator]->is(T_DOUBLE_COLON) === true) {
			if ($before->is([...self::NAME_TOKENS, T_STATIC]) === true) {
				return $this->known(status: $this->classify(type: $before->text, context: $context));
			}

			if ($before->is(T_VARIABLE) === true) {
				return $this->statusOf(name: substr($before->text, 1), types: $types);
			}

			return 'unknown';
		}

		if ($before->text === '$this') {
			return $this->known(status: $this->classify(type: 'static', context: $context));
		}

		if ($before->is(T_VARIABLE) === true) {
			return $this->statusOf(name: substr($before->text, 1), types: $types);
		}

		if ($before->is(T_STRING) === true && ($tokens[$operator - 2] ?? null)?->is(self::OBJECT_OPERATORS) === true
			&& ($tokens[$operator - 3] ?? null)?->text === '$this'
		) {
			return $this->statusOf(name: '->'.$before->text, types: $types);
		}

		if ($before->text === ')') {
			return $this->known(status: $this->typeOfCall(tokens: $tokens, close: $operator - 1, context: $context));
		}

		return 'unknown';
	}//end receiverOf()

	/**
	 * What a parenthesised expression or call ending at `)` returns: the
	 * class of `(new X)`, of `->get(X::class)`/`->query(X::class)` or of a
	 * lookup by a class-name string; anything else is unknown.
	 *
	 * @param list<PhpToken>                                                 $tokens  The significant tokens
	 * @param int                                                            $close   The index of `)`
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context The file's context
	 *
	 * @return string
	 */
	private function typeOfCall(array $tokens, int $close, array $context): string {
		$open = $this->matching(tokens: $tokens, open: $close);
		if (($tokens[$open + 1] ?? null)?->is(T_NEW) === true) {
			$class = ($tokens[$open + 2] ?? null);
			if ($class?->is([...self::NAME_TOKENS, T_STATIC]) === true) {
				return $this->classify(type: $class->text, context: $context);
			}

			return 'unknown';
		}

		$callee = ($tokens[$open - 1] ?? null);
		if ($callee?->is(T_STRING) !== true || in_array(strtolower($callee->text), ['get', 'query'], true) === false
			|| ($tokens[$open - 2] ?? null)?->is([...self::OBJECT_OPERATORS, T_DOUBLE_COLON]) !== true
		) {
			return 'unknown';
		}

		$argument = array_slice($tokens, $open + 1, $close - $open - 1);
		if (count($argument) > 2 && $argument[0]->is(T_STRING) === true && $argument[1]->text === ':') {
			$argument = array_slice($argument, 2);
		}

		if (count($argument) === 3 && $argument[0]->is(self::NAME_TOKENS) === true && $argument[1]->is(T_DOUBLE_COLON) === true
			&& $argument[2]->is(T_CLASS) === true
		) {
			return $this->classify(type: $argument[0]->text, context: $context);
		}

		if (count($argument) === 1 && $argument[0]->is(T_CONSTANT_ENCAPSED_STRING) === true) {
			return $this->classify(type: '\\'.ltrim(stripslashes(trim($argument[0]->text, '\'"')), '\\'), context: $context);
		}

		return 'unknown';
	}//end typeOfCall()

	/**
	 * The index of the bracket matching the one at $open, searching forward
	 * from an opening bracket or backward from a closing one.
	 *
	 * @param list<PhpToken> $tokens The significant tokens
	 * @param int            $open   The bracket's index
	 *
	 * @return int
	 */
	private function matching(array $tokens, int $open): int {
		$pairs = ['(' => ')', '[' => ']', '{' => '}', ')' => '(', ']' => '[', '}' => '{'];
		$start = $tokens[$open]->text;
		$step = -1;
		if (in_array($start, ['(', '[', '{'], true) === true) {
			$step = 1;
		}

		$depth = 0;
		for ($i = $open; $i >= 0 && $i < count($tokens); $i += $step) {
			if ($tokens[$i]->text === $start || ($step === 1 && $start === '{' && $tokens[$i]->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES]) === true)) {
				$depth++;
			} elseif ($tokens[$i]->text === ($pairs[$start] ?? '')) {
				$depth--;
				if ($depth === 0) {
					return $i;
				}
			}
		}

		return $open;
	}//end matching()

	/**
	 * Whether a name may hold a share mapper: 'share' if any declaration of
	 * it may, 'unknown' if any cannot be followed or there is none (an
	 * inherited property), 'other' only if every one is another class.
	 *
	 * @param string                     $name  The variable or property name
	 * @param array<string,list<string>> $types What each name may hold
	 * @param array<string,bool>         $seen  Names already followed
	 *
	 * @return string
	 */
	private function statusOf(string $name, array $types, array $seen=[]): string {
		$statuses = [];
		foreach (($types[$name] ?? []) as $entry) {
			$copied = substr($entry, 1);
			if ($entry[0] !== '$') {
				$statuses[] = $entry;
			} elseif (isset($seen[$copied]) === false) {
				$statuses[] = $this->statusOf(name: $copied, types: $types, seen: $seen + [$name => true]);
			}
		}

		foreach (['share', 'unknown', 'other'] as $status) {
			if (in_array($status, $statuses, true) === true) {
				return $status;
			}
		}

		return 'unknown';
	}//end statusOf()

	/**
	 * A class status as a receiver: a type that cannot receive a call is
	 * not proof of another class.
	 *
	 * @param string $status The status from classify()
	 *
	 * @return string
	 */
	private function known(string $status): string {
		if ($status === 'none') {
			return 'unknown';
		}

		return $status;
	}//end known()

	/**
	 * Whether a type names a share mapper ('share': the mapper, a subclass,
	 * a parent class or interface of it, or any class with a mapper's short
	 * name), another existing class ('other'), a type that cannot receive a
	 * call ('none'), or something that cannot be resolved ('unknown').
	 *
	 * @param string                                                         $type    The type as written
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context The file's context
	 *
	 * @return string
	 */
	private function classify(string $type, array $context): string {
		$lower = strtolower(ltrim($type, '?'));
		if (in_array($lower, self::SCALAR_TYPES, true) === true) {
			return 'none';
		}

		$fqcn = $this->resolve(type: ltrim($type, '?'), context: $context);
		if ($fqcn === null) {
			return 'unknown';
		}

		$short = strtolower(substr((string)strrchr('\\'.$fqcn, '\\'), 1));
		foreach (self::MAPPERS as $mapper) {
			if ($short === strtolower(substr((string)strrchr('\\'.$mapper, '\\'), 1))) {
				return 'share';
			}
		}

		try {
			$exists = (class_exists($fqcn) === true || interface_exists($fqcn) === true);
		} catch (Throwable) {
			$exists = false;
		}

		if ($exists === false) {
			return 'unknown';
		}

		foreach (self::MAPPERS as $mapper) {
			if (is_a($fqcn, $mapper, true) === true || is_a($mapper, $fqcn, true) === true) {
				return 'share';
			}
		}

		return 'other';
	}//end classify()

	/**
	 * The fully qualified class a type names in a file.
	 *
	 * @param string                                                         $type    The type as written
	 * @param array{namespace:string,uses:array<string,string>,class:?string} $context The file's context
	 *
	 * @return string|null
	 */
	private function resolve(string $type, array $context): ?string {
		$lower = strtolower($type);
		if ($lower === 'self' || $lower === 'static') {
			return $context['class'];
		}

		if ($lower === 'parent') {
			$parent = false;
			try {
				if ($context['class'] !== null && class_exists($context['class']) === true) {
					$parent = get_parent_class($context['class']);
				}
			} catch (Throwable) {
				$parent = false;
			}

			if ($parent === false) {
				return null;
			}

			return $parent;
		}

		if (str_starts_with($type, '\\') === true) {
			return substr($type, 1);
		}

		if (str_starts_with($lower, 'namespace\\') === true) {
			return ltrim($context['namespace'].'\\'.substr($type, 10), '\\');
		}

		$parts = explode('\\', $type, 2);
		$import = ($context['uses'][strtolower($parts[0])] ?? null);
		if ($import !== null && isset($parts[1]) === true) {
			return $import.'\\'.$parts[1];
		}

		if ($import !== null) {
			return $import;
		}

		return ltrim($context['namespace'].'\\'.$type, '\\');
	}//end resolve()

	/**
	 * The string literals that only make up a message: the first argument
	 * of a PSR-3 log call (`->error(`, `->warning(`, …) or a `message:`
	 * named argument. Context arrays and every other argument still count.
	 *
	 * @param list<PhpToken> $all All tokens
	 *
	 * @return array<int,true> Index in $all => true
	 */
	private function messageLiterals(array $all): array {
		$sig = array_keys(array_filter($all, static fn (PhpToken $token): bool => $token->isIgnorable() === false));
		$levels = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];
		$literals = [];
		foreach ($sig as $p => $k) {
			$token = $all[$k];
			if ($token->is(T_STRING) === false) {
				continue;
			}

			$previous = $all[($sig[$p - 1] ?? -1)] ?? null;
			$next = $all[($sig[$p + 1] ?? -1)] ?? null;
			$logCall = (in_array(strtolower($token->text), $levels, true) === true && $previous?->is(self::OBJECT_OPERATORS) === true && $next?->text === '(');
			$named = (strtolower($token->text) === 'message' && $next?->text === ':' && in_array($previous?->text, ['(', ','], true) === true);
			if ($logCall === false && $named === false) {
				continue;
			}

			$depth = 0;
			for ($q = $p + 2; isset($sig[$q]) === true; $q++) {
				$text = $all[$sig[$q]]->text;
				if (in_array($text, ['(', '['], true) === true) {
					$depth++;
				} elseif (in_array($text, [')', ']'], true) === true) {
					if ($depth === 0) {
						break;
					}

					$depth--;
				} elseif ($depth === 0 && $text === ',') {
					break;
				} elseif ($all[$sig[$q]]->is([T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE]) === true) {
					$literals[$sig[$q]] = true;
				}
			}
		}//end foreach

		return $literals;
	}//end messageLiterals()

	/**
	 * The line numbers in a source that match a pattern.
	 *
	 * @param string $pattern The pattern
	 * @param string $source  The source
	 *
	 * @return list<int>
	 */
	private function linesMatching(string $pattern, string $source): array {
		// Comments and log messages keep their line breaks but lose their text.
		$all = PhpToken::tokenize($source);
		$messages = $this->messageLiterals(all: $all);
		$code = '';
		foreach ($all as $k => $token) {
			if ($token->is([T_COMMENT, T_DOC_COMMENT]) === true || isset($messages[$k]) === true) {
				$code .= str_repeat("\n", substr_count($token->text, "\n"));
				continue;
			}

			$code .= $token->text;
		}

		$lines = [];
		preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);
		foreach ($matches[0] as [, $offset]) {
			$lines[] = substr_count(substr($code, 0, $offset), "\n") + 1;
		}

		return $lines;
	}//end linesMatching()

	/**
	 * No runtime path calls a share-row finder outside the allowlist, and an
	 * allowed file calls it exactly as often as listed.
	 *
	 * @return void
	 */
	public function testNoPathReadsShareRowsAroundTheJudge(): void {
		$offending = [];
		foreach ($this->rawCalls() as $file => $finders) {
			foreach ($finders as $finder => $lines) {
				$allowed = (self::ALLOWED[$file][$finder][0] ?? 0);
				if (count($lines) !== $allowed) {
					$offending[] = 'lib/'.$file.':'.implode(',', $lines).' '.$finder.'() called '.count($lines).' time(s), '.$allowed.' allowed';
				}
			}
		}

		$this->assertSame(
			expected: [],
			actual: $offending,
			message: "Read share rows through ShareRowJudge, which asks the pending quarantine scan about each row,\n"
				.'or add the call to ALLOWED with the reason it needs no judge.'
		);
	}//end testNoPathReadsShareRowsAroundTheJudge()

	/**
	 * No runtime path queries the share-targets table by name outside the
	 * mappers and the listed files.
	 *
	 * @return void
	 */
	public function testNoPathQueriesTheShareTargetsTableAroundTheMappers(): void {
		$offending = [];
		foreach ($this->tableReads() as $file => $lines) {
			$allowed = (self::TABLE_ALLOWED[$file][0] ?? 0);
			if (count($lines) !== $allowed) {
				$offending[] = 'lib/'.$file.':'.implode(',', $lines).' names the table '.count($lines).' time(s), '.$allowed.' allowed';
			}
		}

		$this->assertSame(
			expected: [],
			actual: $offending,
			message: 'Read share rows through the mappers and ShareRowJudge, not by table name, or add the read to TABLE_ALLOWED.'
		);
	}//end testNoPathQueriesTheShareTargetsTableAroundTheMappers()

	/**
	 * Every allowlist entry still matches, so the lists do not rot.
	 *
	 * @return void
	 */
	public function testEveryAllowedCallStillExists(): void {
		$calls = $this->rawCalls();
		$reads = $this->tableReads();
		$stale = [];
		foreach (self::ALLOWED as $file => $finders) {
			foreach (array_keys($finders) as $finder) {
				if (isset($calls[$file][$finder]) === false) {
					$stale[] = $file.' '.$finder;
				}
			}
		}

		foreach (array_keys(self::TABLE_ALLOWED) as $file) {
			if (isset($reads[$file]) === false) {
				$stale[] = $file.' (table)';
			}
		}

		$this->assertSame(expected: [], actual: $stale, message: 'Remove the allowlist entries that no longer match a call.');
	}//end testEveryAllowedCallStillExists()

	/**
	 * The finders are read from all three mappers, traits included, and
	 * leave out their writes and QBMapper's own methods.
	 *
	 * @return void
	 */
	public function testFindersAreReadFromEveryMapper(): void {
		$finders = array_values($this->finders());
		foreach (['findBySourceSecret', 'hasDirectShareBetween', 'findEndingBetween', 'findByGroupShare', 'findQuarantinedById'] as $finder) {
			$this->assertContains(needle: $finder, haystack: $finders);
		}

		foreach (['deleteBySourceSecret', 'deleteByGroupShare', 'insert', 'update', 'delete', 'getTableName', '__construct'] as $method) {
			$this->assertNotContains(needle: $method, haystack: $finders);
		}
	}//end testFindersAreReadFromEveryMapper()

	/**
	 * SHARED_NAMES lists exactly the finder names another class in lib/
	 * declares, so a name stays fail-closed until someone looks at it.
	 *
	 * @return void
	 */
	public function testSharedNamesAreTheOnesDeclaredElsewhere(): void {
		$listed = array_keys(self::SHARED_NAMES);
		sort($listed);

		$this->assertSame(
			expected: $this->namesDeclaredElsewhere(),
			actual: $listed,
			message: 'A finder name another class declares goes in SHARED_NAMES; one nobody else declares comes out of it.'
		);
	}//end testSharedNamesAreTheOnesDeclaredElsewhere()

	/**
	 * The scan follows a receiver through a group-use alias, a nullable
	 * promoted property, a local copy, a property typed only by `@var`, a
	 * container lookup by `::class` or by string, `->query()` and `new`; it
	 * reports an inherited property, a getter, a callable string, an untyped
	 * or by-reference parameter and a variable passed to a by-reference
	 * parameter as unknown; it reports a unique finder name on any receiver; and it lets
	 * a shared name pass only on a receiver proven to be another class.
	 *
	 * @return void
	 */
	public function testTheScanFollowsOrReportsEveryReceiver(): void {
		$source = <<<'PHP'
			<?php
			namespace OCA\Keepiq\Service;
			use OCA\Keepiq\Db\{SecretMapper, ShareTargetMapper as Rows};
			use OCA\Keepiq\Db\QuarantinedShareTargetMapper as Held;
			use OCP\Server;
			class Sample extends Base {
				/** @var Rows */
				private $docTyped;
				public function __construct(private ?Rows $targets, private SecretMapper $secrets, private Held|null $held, private $c,
					private \OCA\Keepiq\Db\BulkGrantShareTargetMapper $bulk) {}
				private function mapper() { return $this->targets; }
				public function run(Rows $param, $container, null|Rows $aliased): void {
					$this->targets?->findById(id: 'a');
					$rows = $this->targets; $rows->findBySourceSecret('b');
					$this->docTyped->findById('c');
					$this->inherited->findById('d');
					$this->mapper()->findById('e');
					$container->query(Rows::class)->findById('f');
					Server::get('OCA\\Keepiq\\Db\\ShareTargetMapper')->findById('g');
					$fetched = $this->c->get("OCA\\Keepiq\\Db\\ShareTargetMapper"); $fetched->findBySourceSecret('h');
					call_user_func([$this->secrets, 'findById'], 'i');
					$callable = [$this->held, 'findQuarantinedById'];
					$this->held->findQuarantinedBySourceSecret('j');
					$this->secrets->findPage('', 1);
					$param->hasDirectShareBetween('k', 'l');
					$this->bulk->findByTeamFolder('m');
					(new Rows($db))->findById('n');
					$aliased -> findEndingBetween(null, $now);
					\OCP\Server::get(\OCA\Keepiq\Db\BulkGrantShareTargetMapper::class)->findByGroupShare('o');
					$this->secrets->findById('p');
					$secret = $this->secrets; $secret->findBySourceSecret('q');
					(new SecretMapper($db))->findById('r');
				}
			}
			PHP;

		$calls = $this->callsIn(source: $source);
		ksort($calls);
		$this->assertSame(
			expected: [
				'findByGroupShare' => [29],
				'findById' => [13, 15, 16, 17, 18, 19, 21, 27],
				'findBySourceSecret' => [14, 20],
				'findByTeamFolder' => [26],
				'findEndingBetween' => [28],
				'findPage' => [24],
				'findQuarantinedById' => [22],
				'findQuarantinedBySourceSecret' => [23],
				'hasDirectShareBetween' => [25],
			],
			actual: $calls
		);

		// An untyped or by-reference declaration of a name makes it unknown,
		// even when another declaration in the file types it as another class;
		// so does `global`. A parameter or local never types a `$this->`
		// property of the same name, so an inherited or untyped property stays
		// unknown; a promoted one is typed.
		$evasive = <<<'PHP'
			<?php
			namespace OCA\Keepiq\Service;
			use OCA\Keepiq\Db\AttachmentMapper;
			class Evasive {
				public function __construct(private AttachmentMapper $mapper, private AttachmentMapper $m, private AttachmentMapper $typed) {}
				private function rowsOf($mapper) { return $mapper->findBySourceSecret('x'); }
				private function viaArrow(): array { return array_map(fn ($m) => $m->findBySourceSecret('y'), []); }
				private function pick(?AttachmentMapper &$out): void { $out = $this->shareTargets; }
				private function viaOut(AttachmentMapper $held): void { $this->pick($held); $held->findBySourceSecret('z'); }
				private function byReference(AttachmentMapper &$ref): void { $ref->findBySourceSecret('w'); }
				private function control(AttachmentMapper $typed): void { $typed->findBySourceSecret('v'); }
				protected $untyped;
				private function collide(AttachmentMapper $inherited, AttachmentMapper $untyped, AttachmentMapper $glob): void { $this->inherited->findBySourceSecret('i'); $this->untyped->findBySourceSecret('j'); }
				private function viaGlobal(): void { global $glob; $glob->findBySourceSecret('k'); }
				private function promoted(): void { $this->typed->findBySourceSecret('l'); }
			}
			PHP;

		$this->assertSame(expected: ['findBySourceSecret' => [6, 7, 9, 10, 13, 13, 14]], actual: $this->callsIn(source: $evasive));
	}//end testTheScanFollowsOrReportsEveryReceiver()

	/**
	 * The scan sees calls through a promoted property, a nullable, union or
	 * aliased type, a plain variable, a container lookup and a variable
	 * assigned from one, for both mappers, so a raw call cannot hide behind
	 * the syntax; and a query naming the table, also in raw `*PREFIX*` SQL or
	 * through a constant, but not in a comment.
	 *
	 * @return void
	 */
	public function testTheScanSeesEveryCallShape(): void {
		$source = implode("\n", [
			'<?php',
			'use OCA\\Keepiq\\Db\\ShareTargetMapper as Rows;',
			'class X { public function __construct(private ?ShareTargetMapper $rows, ShareTargetMapper|null $other,',
			' null|Rows $aliased, private BulkGrantShareTargetMapper $bulk) {',
			' $this->rows?->findByRecipientSecret(recipientSecretId: "a");',
			' $other->findByTargetUser("b");',
			' $this -> rows -> findBySourceSecret("c");',
			' $aliased->findById("d");',
			' Server::get(ShareTargetMapper::class)->findPage("", 1);',
			' $fetched = \\OCP\\Server::get(\\OCA\\Keepiq\\Db\\ShareTargetMapper::class);',
			' $fetched->findEndingBetween(null, $now);',
			' $this->bulk->findByGroupShare("e");',
			' $this->bulk->findByTeamFolderAndTargetUser("f", "g");',
			' } }',
		]);

		$calls = $this->callsIn(source: $source);
		ksort($calls);
		$this->assertSame(
			expected: [
				'findByGroupShare' => [12],
				'findById' => [8],
				'findByRecipientSecret' => [5],
				'findBySourceSecret' => [7],
				'findByTargetUser' => [6],
				'findByTeamFolderAndTargetUser' => [13],
				'findEndingBetween' => [11],
				'findPage' => [9],
			],
			actual: $calls
		);
		$this->assertSame(
			expected: [2],
			actual: $this->linesMatching(pattern: self::TABLE_PATTERN, source: "<?php\n\$qb->from('keepiq_share_targets');")
		);
		$this->assertSame(
			expected: [2],
			actual: $this->linesMatching(pattern: self::TABLE_PATTERN, source: "<?php\n\$db->executeQuery('SELECT * FROM *PREFIX*keepiq_share_targets');")
		);
		$this->assertSame(
			expected: [],
			actual: $this->linesMatching(
				pattern: self::TABLE_PATTERN,
				source: "<?php\n// keepiq_share_targets\n/** share_targets */\n# share_targets\n\$qb->from('keepiq_quarantined_share_targets_x');"
			)
		);

		// A log message naming the table does not count, so a new read cannot
		// take the place of an edited message; the log call's context does.
		$this->assertSame(
			expected: [4, 5],
			actual: $this->linesMatching(
				pattern: self::TABLE_PATTERN,
				source: implode("\n", [
					'<?php',
					'$this->logger->error(\'keepiq_share_targets row \'.$id.\' held back\', [\'app\' => \'keepiq\']);',
					'$this->report(output: $o, message: "quarantined keepiq_share_targets row {$id}");',
					'$qb->from(\'keepiq_share_targets\');',
					'$this->logger->warning(\'moved\', [\'table\' => \'keepiq_share_targets\']);',
				])
			)
		);

		// A constant holding the table name counts on every read of it, in its
		// own class (`self::`, `static::`, `$this::`) and through its class name
		// elsewhere; so does a constant aliasing it or concatenating the name.
		$scanner = implode("\n", [
			'<?php',
			'class Scan {',
			' private const TABLE = \'keepiq_share_targets\';',
			' public function a($qb) { $qb->from(self::TABLE); }',
			' public function b($qb) { $qb->from(static::TABLE)->join(Scan::TABLE); }',
			' public function c() { return self::TABLE_COUNT; }',
			' private const ALIAS = self::TABLE, OTHER = \'x\';',
			' public function d($qb) { $qb->from($this::TABLE)->join(self::ALIAS)->join(self::OTHER); }',
			' private const JOINED = \'*PREFIX*\'.\'keepiq_share_targets\';',
			' public function e($qb) { $qb->from(self::JOINED); }',
			'}',
		]);
		$this->assertSame(expected: [3, 4, 5, 5, 7, 8, 8, 9, 10], actual: $this->tableLinesIn(source: $scanner));
		$this->assertSame(
			expected: [2],
			actual: $this->tableLinesIn(
				source: "<?php\n\$qb->from(\\OCA\\Keepiq\\Service\\Scan::TABLE);\n\$qb->from(Other::TABLE);",
				constants: ['scan' => ['TABLE']]
			)
		);

		// Only the three share mappers and the migrations may name the table.
		$this->assertTrue(condition: $this->mayNameTheTable(file: 'Db/ShareTargetMapper.php'));
		$this->assertTrue(condition: $this->mayNameTheTable(file: 'Db/QuarantinedShareTargetMapper.php'));
		$this->assertTrue(condition: $this->mayNameTheTable(file: 'Migration/Version001015Date20261021120000.php'));
		$this->assertFalse(condition: $this->mayNameTheTable(file: 'Db/SecretMapper.php'));
	}//end testTheScanSeesEveryCallShape()
}//end class
