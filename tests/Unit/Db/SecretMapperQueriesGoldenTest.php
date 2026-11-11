<?php

/**
 * The queries SecretMapper and SecretVersionMapper build, pinned call by call.
 *
 * A recording query builder stands in for the database: every builder,
 * expression and function call is written to a trace, and the trace of each
 * mapper method is compared with a golden file recorded on the code before
 * the mappers shared their query steps. Record with KEEPIQ_RECORD_GOLDEN=1.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Unit\Db
 *
 * @author    Conduction Development Team <dev@conductio.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Keepiq\Tests\Unit\Db;

use DateTime;
use DateTimeInterface;
use OCA\Keepiq\Db\SecretMapper;
use OCA\Keepiq\Db\SecretVersionMapper;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use UnitEnum;
use WeakMap;

/**
 * Golden traces of the secret and version mapper queries.
 */
class SecretMapperQueriesGoldenTest extends TestCase {

	private const GOLDEN = __DIR__ . '/golden/secret-mapper-queries.json';

	/**
	 * The calls recorded so far.
	 *
	 * @var array<int,mixed>
	 */
	private array $calls = [];

	/**
	 * The rows the next query answers with.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $rows = [];

	/**
	 * The connection the builders belong to.
	 *
	 * @var IDBConnection|null
	 */
	private ?IDBConnection $db = null;

	/**
	 * The token each returned expression or function object stands for.
	 *
	 * @var WeakMap<object,string>|null
	 */
	private ?WeakMap $tokens = null;

	/**
	 * The mapper calls to pin, by name.
	 *
	 * @return array<string,array{0:string}>
	 */
	public static function cases(): array {
		$names = [
			'findByOwner plain',
			'findByOwner filtered',
			'countByOwner plain',
			'countByOwner filtered',
			'findByName',
			'findByName in folder',
			'findByOwnerUpdatedSince',
			'searchByNameOrUrl',
			'findForUnifiedSearch',
			'countByTypeId',
			'countByTypeId expiring',
			'countByFolder',
			'countByFolderIds',
			'countBySuiteForOwner',
			'countUnaccountedBySuiteForOwner',
			'findFailedBySuiteForOwner',
			'findBySuiteForOwner',
			'deleteByOwnerUser',
			'deleteByOwnerApplication',
			'version countBySuiteForOwner',
			'version countUnaccountedBySuiteForOwner',
			'version findSecretIdsWithSuiteVersionsForOwner',
		];

		$out = [];
		foreach ($names as $name) {
			$out[$name] = [$name];
		}

		return $out;
	}//end cases()

	/**
	 * Run one mapper call.
	 *
	 * @param string $name The case name
	 * @param SecretMapper $secrets The secret mapper
	 * @param SecretVersionMapper $versions The version mapper
	 *
	 * @return mixed What the mapper returned
	 */
	private function callMapper(string $name, SecretMapper $secrets, SecretVersionMapper $versions): mixed {
		$since = new DateTime('2026-01-02 03:04:05');

		return match ($name) {
			'findByOwner plain' => $secrets->findByOwner('user', 'alice'),
			'findByOwner filtered' => $secrets->findByOwner('user', 'alice', 'folder-1', 'updated_at', 'DESC', 20, 40, 'type-1', SecretMapper::STATE_LIVE, true, 'work'),
			'countByOwner plain' => $secrets->countByOwner('application', 'app-1'),
			'countByOwner filtered' => $secrets->countByOwner('user', 'alice', 'folder-1', 'type-1', SecretMapper::STATE_ARCHIVED, true, 'work'),
			'findByName' => $secrets->findByName('application', 'app-1', 'db-password'),
			'findByName in folder' => $secrets->findByName('application', 'app-1', 'db-password', 'folder-1'),
			'findByOwnerUpdatedSince' => $secrets->findByOwnerUpdatedSince('application', 'app-1', $since, 10),
			'searchByNameOrUrl' => $secrets->searchByNameOrUrl('user', 'alice', 'bank', 0),
			'findForUnifiedSearch' => $secrets->findForUnifiedSearch('alice', 'bank', 5),
			'countByTypeId' => $secrets->countByTypeId('type-1'),
			'countByTypeId expiring' => $secrets->countByTypeId('type-1', $since),
			'countByFolder' => $secrets->countByFolder('folder-1'),
			'countByFolderIds' => $secrets->countByFolderIds(['folder-1', 'folder-2']),
			'countBySuiteForOwner' => $secrets->countBySuiteForOwner('suite-1', 'user', 'alice'),
			'countUnaccountedBySuiteForOwner' => $secrets->countUnaccountedBySuiteForOwner('suite-1', 'user', 'alice'),
			'findFailedBySuiteForOwner' => $secrets->findFailedBySuiteForOwner('suite-1', 'user', 'alice', 0),
			'findBySuiteForOwner' => $secrets->findBySuiteForOwner('suite-1', 'user', 'alice', 10, -5),
			'deleteByOwnerUser' => $secrets->deleteByOwnerUser('alice'),
			'deleteByOwnerApplication' => $secrets->deleteByOwnerApplication('app-1'),
			'version countBySuiteForOwner' => $versions->countBySuiteForOwner('suite-1', 'user', 'alice'),
			'version countUnaccountedBySuiteForOwner' => $versions->countUnaccountedBySuiteForOwner('suite-1', 'user', 'alice'),
			'version findSecretIdsWithSuiteVersionsForOwner' => $versions->findSecretIdsWithSuiteVersionsForOwner('suite-1', 'user', 'alice', 10, 20),
		};
	}//end callMapper()

	/**
	 * Each mapper call builds the recorded query and answers the same.
	 *
	 * @param string $name The case name
	 *
	 * @return void
	 */
	#[DataProvider('cases')]
	public function testQueryMatchesGolden(string $name): void {
		$this->calls = [];
		$this->tokens = new WeakMap();
		$this->rows = [['id' => 'row-1']];
		if (str_contains($name, 'count') === true) {
			$this->rows = [['cnt' => '3']];
		}

		if (str_contains($name, 'SecretIds') === true) {
			$this->rows = [['secret_id' => 'secret-1']];
		}

		$db = $this->createMock(IDBConnection::class);
		$this->db = $db;
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingBuilder());
		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $term): string => 'escaped:' . $term);

		$returned = $this->callMapper(name: $name, secrets: new SecretMapper($db), versions: new SecretVersionMapper($db));
		if (is_array($returned) === true) {
			$returned = array_map(static fn ($row) => is_object($row) ? get_class($row) : $row, $returned);
		}

		$trace = ['calls' => $this->calls, 'returned' => $returned];

		$golden = [];
		if (is_file(self::GOLDEN) === true) {
			$golden = json_decode((string)file_get_contents(self::GOLDEN), true);
		}

		if (getenv('KEEPIQ_RECORD_GOLDEN') === '1') {
			$golden[$name] = $trace;
			ksort($golden);
			file_put_contents(self::GOLDEN, json_encode($golden, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		}

		$this->assertSame($golden[$name] ?? null, json_decode((string)json_encode($trace), true));
	}//end testQueryMatchesGolden()

	/**
	 * A query builder that records every call made on it.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function recordingBuilder(): IQueryBuilder&MockObject {
		$qb = $this->createMock(IQueryBuilder::class);
		$expr = $this->recordingTokens(interface: IExpressionBuilder::class);
		$func = $this->recordingTokens(interface: IFunctionBuilder::class);

		foreach ((new ReflectionClass(IQueryBuilder::class))->getMethods() as $method) {
			$name = $method->getName();
			if (in_array($name, ['expr', 'func', 'createNamedParameter', 'executeQuery', 'executeStatement', 'getConnection', 'getSQL'], true) === true) {
				continue;
			}

			$type = $method->getReturnType();
			$typeName = IQueryBuilder::class;
			if ($type instanceof ReflectionNamedType && interface_exists($type->getName()) === true) {
				$typeName = $type->getName();
			}

			$qb->method($name)->willReturnCallback(function (...$args) use ($name, $qb, $typeName) {
				$args = $this->describe(args: $args);
				$this->calls[] = [$name, $args];
				if ($typeName === IQueryBuilder::class) {
					return $qb;
				}

				return $this->tokenOf(typeName: $typeName, token: $name . '(' . json_encode($args) . ')');
			});
		}

		$qb->method('expr')->willReturn($expr);
		$qb->method('getConnection')->willReturn($this->db);
		$qb->method('getSQL')->willReturnCallback(function () {
			$this->calls[] = ['getSQL', []];
			return 'SUBQUERY';
		});
		$qb->method('func')->willReturn($func);
		$qb->method('createNamedParameter')->willReturnCallback(function (...$args) {
			$this->calls[] = ['createNamedParameter', $this->describe(args: $args)];
			$parameter = $this->createMock(IParameter::class);
			$parameter->method('__toString')->willReturn(':param');
			return $parameter;
		});
		$qb->method('executeQuery')->willReturnCallback(function () {
			$this->calls[] = ['executeQuery', []];
			return $this->recordingResult();
		});
		$qb->method('executeStatement')->willReturnCallback(function () {
			$this->calls[] = ['executeStatement', []];
			return 2;
		});

		return $qb;
	}//end recordingBuilder()

	/**
	 * An expression or function builder whose every method answers a token
	 * naming the call.
	 *
	 * @param class-string $interface The builder interface
	 *
	 * @return MockObject
	 */
	private function recordingTokens(string $interface): MockObject {
		$builder = $this->createMock($interface);
		foreach ((new ReflectionClass($interface))->getMethods() as $method) {
			$name = $method->getName();
			$type = $method->getReturnType();
			$typeName = 'string';
			if ($type instanceof ReflectionNamedType) {
				$typeName = $type->getName();
			}

			$builder->method($name)->willReturnCallback(function (...$args) use ($name, $typeName) {
				$args = $this->describe(args: $args);
				$this->calls[] = [$name, $args];
				$token = $name . '(' . implode(',', array_map(static fn ($a) => is_scalar($a) ? (string)$a : json_encode($a), $args)) . ')';
				return $this->tokenOf(typeName: $typeName, token: $token);
			});
		}

		return $builder;
	}//end recordingTokens()

	/**
	 * The value a token-answering method returns for its declared type.
	 *
	 * @param string $typeName The declared return type
	 * @param string $token The call's token
	 *
	 * @return mixed
	 */
	private function tokenOf(string $typeName, string $token): mixed {
		if (in_array($typeName, [ICompositeExpression::class, IQueryFunction::class], true) === false) {
			return $token;
		}

		$value = $this->createMock($typeName);
		if (method_exists($typeName, '__toString') === true) {
			$value->method('__toString')->willReturn($token);
		}

		$this->tokens[$value] = $token;

		return $value;
	}//end tokenOf()

	/**
	 * A result that answers the prepared rows once, recording each read.
	 *
	 * @return IResult
	 */
	private function recordingResult(): IResult {
		$rows = $this->rows;
		$result = $this->createMock(IResult::class);
		foreach (['fetch', 'fetchAssociative'] as $method) {
			$result->method($method)->willReturnCallback(function () use ($method, &$rows) {
				$this->calls[] = [$method, []];
				return array_shift($rows) ?? false;
			});
		}

		$result->method('fetchAll')->willReturnCallback(function () use (&$rows) {
			$this->calls[] = ['fetchAll', []];
			$all = $rows;
			$rows = [];
			return $all;
		});
		$result->method('closeCursor')->willReturnCallback(function () {
			$this->calls[] = ['closeCursor', []];
			return true;
		});

		return $result;
	}//end recordingResult()

	/**
	 * Describe call arguments as JSON-stable values.
	 *
	 * @param array<int,mixed> $args The arguments
	 *
	 * @return array<int,mixed>
	 */
	private function describe(array $args): array {
		return array_map(
			function ($arg) {
				if ($arg instanceof DateTimeInterface) {
					return 'DateTime';
				}

				if ($arg instanceof UnitEnum) {
					return $arg->name;
				}

				if (is_object($arg) === true && isset($this->tokens[$arg]) === true) {
					return $this->tokens[$arg];
				}

				if ($arg instanceof IQueryBuilder) {
					return 'subquery';
				}

				if (is_object($arg) === true && method_exists($arg, '__toString') === true) {
					return (string)$arg;
				}

				if (is_object($arg) === true) {
					return get_class($arg);
				}

				return $arg;
			},
			$args
		);
	}//end describe()
}//end class
