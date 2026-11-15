<?php

/**
 * A recording IDBConnection for mapper unit tests without a database.
 *
 * Every query builder the mapper asks for is recorded as a list of
 * operations rendered to short strings (`where(eq(owner_id, :p0))`) plus the
 * values bound to its named parameters, so a test can assert the exact
 * table, conditions, ordering and bound values a mapper method produces.
 * executeQuery() hands out the next queued result set; executeStatement()
 * answers the queued affected-row count.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests\Support
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

namespace OCA\Keepiq\Tests\Support;

use DateTimeInterface;
use OCP\DB\IResult;
use OCP\DB\QueryBuilder\ICompositeExpression;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IFunctionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\DB\QueryBuilder\IQueryFunction;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Use from a PHPUnit TestCase.
 */
trait RecordingDatabase {

	/**
	 * Recorded queries: ops, bound params, how each ran.
	 *
	 * @var list<array{ops: list<string>, params: array<string,mixed>, types: array<string,mixed>, ran: string|null}>
	 */
	private array $queries = [];

	/**
	 * Result sets handed to executeQuery(), in order.
	 *
	 * @var list<list<array<string,mixed>>>
	 */
	private array $resultSets = [];

	/**
	 * Affected-row counts handed to executeStatement(), in order (default 1).
	 *
	 * @var list<int>
	 */
	private array $affected = [];

	/**
	 * Connection-level calls (transactions).
	 *
	 * @var list<string>
	 */
	private array $connectionCalls = [];

	/**
	 * Running parameter counter across all queries.
	 *
	 * @var int
	 */
	private int $paramCounter = 0;

	/**
	 * A connection whose query builders record into $this->queries.
	 *
	 * @return IDBConnection&MockObject
	 */
	private function recordingConnection(): IDBConnection {
		$db = $this->createMock(IDBConnection::class);
		$db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->recordingQueryBuilder());
		foreach (['beginTransaction', 'commit', 'rollBack'] as $method) {
			$db->method($method)->willReturnCallback(function () use ($method): void {
				$this->connectionCalls[] = $method;
			});
		}

		$db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => $value);
		return $db;
	}//end recordingConnection()

	/**
	 * Queue a result set for the next executeQuery().
	 *
	 * @param list<array<string,mixed>> $rows The rows
	 *
	 * @return void
	 */
	private function queueRows(array $rows): void {
		$this->resultSets[] = $rows;
	}//end queueRows()

	/**
	 * The ops of query $index.
	 *
	 * @param int $index Query number, in creation order
	 *
	 * @return list<string>
	 */
	private function opsOf(int $index): array {
		return $this->queries[$index]['ops'];
	}//end opsOf()

	/**
	 * The bound parameter values of query $index, by placeholder.
	 *
	 * @param int $index Query number, in creation order
	 *
	 * @return array<string,mixed>
	 */
	private function paramsOf(int $index): array {
		return $this->queries[$index]['params'];
	}//end paramsOf()

	/**
	 * How query $index ran: 'query', 'statement' or null.
	 *
	 * @param int $index Query number, in creation order
	 *
	 * @return string|null
	 */
	private function ranOf(int $index): ?string {
		return $this->queries[$index]['ran'];
	}//end ranOf()

	/**
	 * One recording query builder.
	 *
	 * @return IQueryBuilder&MockObject
	 */
	private function recordingQueryBuilder(): IQueryBuilder {
		$index = count($this->queries);
		$this->queries[] = ['ops' => [], 'params' => [], 'types' => [], 'ran' => null];
		$qb = $this->createMock(IQueryBuilder::class);

		$fluent = [
			'select', 'selectAlias', 'selectDistinct', 'addSelect', 'delete', 'update', 'insert', 'from', 'join',
			'innerJoin', 'leftJoin', 'rightJoin', 'set', 'where', 'andWhere', 'orWhere', 'groupBy', 'addGroupBy',
			'setValue', 'values', 'having', 'andHaving', 'orHaving', 'orderBy', 'addOrderBy', 'setMaxResults',
			'setFirstResult', 'setParameter', 'setParameters', 'automaticTablePrefix', 'hintShardKey', 'runAcrossAllShards',
		];
		foreach ($fluent as $method) {
			if (method_exists(IQueryBuilder::class, $method) === false) {
				continue;
			}

			$qb->method($method)->willReturnCallback(function (...$args) use ($index, $method, $qb) {
				$this->queries[$index]['ops'][] = $method . '(' . $this->renderArgs(args: $args) . ')';
				return $qb;
			});
		}

		$qb->method('expr')->willReturn($this->stringBuilder(interface: IExpressionBuilder::class));
		$qb->method('func')->willReturn($this->stringBuilder(interface: IFunctionBuilder::class));
		$qb->method('getSQL')->willReturn('SQL');
		$qb->method('getLastInsertId')->willReturn(42);
		$qb->method('createFunction')->willReturnCallback(fn (string $call) => $this->queryFunction(text: $call));
		$qb->method('createNamedParameter')->willReturnCallback(function ($value, $type = IQueryBuilder::PARAM_STR) use ($index) {
			$name = ':p' . $this->paramCounter++;
			$this->queries[$index]['params'][$name] = $value;
			$this->queries[$index]['types'][$name] = $type;
			$parameter = $this->createMock(IParameter::class);
			$parameter->method('__toString')->willReturn($name);
			return $parameter;
		});
		$qb->method('executeQuery')->willReturnCallback(function () use ($index): IResult {
			$this->queries[$index]['ran'] = 'query';
			return $this->recordingResult(rows: (array_shift($this->resultSets) ?? []));
		});
		$qb->method('executeStatement')->willReturnCallback(function () use ($index): int {
			$this->queries[$index]['ran'] = 'statement';
			return (array_shift($this->affected) ?? 1);
		});

		return $qb;
	}//end recordingQueryBuilder()

	/**
	 * An expression or function builder whose every method renders to a string token.
	 *
	 * @param class-string $interface The builder interface
	 *
	 * @return MockObject
	 */
	private function stringBuilder(string $interface): MockObject {
		$builder = $this->createMock($interface);
		foreach ((new ReflectionClass($interface))->getMethods() as $method) {
			$name = $method->getName();
			$type = $method->getReturnType();
			$typeName = ($type instanceof ReflectionNamedType) ? $type->getName() : 'string';
			$builder->method($name)->willReturnCallback(function (...$args) use ($name, $typeName) {
				$text = $name . '(' . $this->renderArgs(args: $args) . ')';
				if ($typeName === ICompositeExpression::class || $typeName === IQueryFunction::class) {
					return $this->queryFunction(text: $text, interface: $typeName);
				}

				return $text;
			});
		}

		return $builder;
	}//end stringBuilder()

	/**
	 * A stringable token of the given interface.
	 *
	 * @param string $text The rendering
	 * @param class-string $interface IQueryFunction or ICompositeExpression
	 *
	 * @return IQueryFunction|ICompositeExpression
	 */
	private function queryFunction(string $text, string $interface = IQueryFunction::class): IQueryFunction|ICompositeExpression {
		if ($interface === ICompositeExpression::class) {
			return new class($text) implements ICompositeExpression {
				/**
				 * Constructor.
				 *
				 * @param string $text The rendering
				 */
				public function __construct(
					private string $text,
				) {
				}

				/**
				 * Not used by the mappers under test.
				 *
				 * @param array<mixed> $parts Parts
				 *
				 * @return ICompositeExpression
				 */
				public function addMultiple(array $parts = []): ICompositeExpression {
					return $this;
				}

				/**
				 * Not used by the mappers under test.
				 *
				 * @param mixed $part Part
				 *
				 * @return ICompositeExpression
				 */
				public function add($part): ICompositeExpression {
					return $this;
				}

				/**
				 * Not used by the mappers under test.
				 *
				 * @return int
				 */
				public function count(): int {
					return 0;
				}

				/**
				 * Not used by the mappers under test.
				 *
				 * @return string
				 */
				public function getType(): string {
					return 'composite';
				}

				/**
				 * The rendering.
				 *
				 * @return string
				 */
				public function __toString(): string {
					return $this->text;
				}
			};
		}//end if

		$token = $this->createMock(IQueryFunction::class);
		$token->method('__toString')->willReturn($text);
		return $token;
	}//end queryFunction()

	/**
	 * A result over the given rows.
	 *
	 * @param list<array<string,mixed>> $rows The rows
	 *
	 * @return IResult&MockObject
	 */
	private function recordingResult(array $rows): IResult {
		$result = $this->createMock(IResult::class);
		$next = static function () use (&$rows) {
			return (array_shift($rows) ?? false);
		};
		$result->method('fetch')->willReturnCallback($next);
		$result->method('fetchAssociative')->willReturnCallback($next);
		$result->method('fetchOne')->willReturnCallback(static function () use (&$rows) {
			$row = array_shift($rows);
			return ($row === null) ? false : reset($row);
		});
		$result->method('fetchAll')->willReturnCallback(static function (int $mode = \PDO::FETCH_ASSOC) use (&$rows) {
			$all = $rows;
			$rows = [];
			if ($mode === \PDO::FETCH_COLUMN) {
				return array_map(static fn (array $row) => reset($row), $all);
			}

			return $all;
		});
		$result->method('fetchFirstColumn')->willReturnCallback(static function () use (&$rows) {
			$all = array_map(static fn (array $row) => reset($row), $rows);
			$rows = [];
			return $all;
		});
		$result->method('closeCursor')->willReturn(true);
		return $result;
	}//end recordingResult()

	/**
	 * Render call arguments compactly.
	 *
	 * @param array<int,mixed> $args The arguments
	 *
	 * @return string
	 */
	private function renderArgs(array $args): string {
		// Mocks receive defaulted parameters too; trailing null/'' defaults are noise.
		while ($args !== [] && (end($args) === null || end($args) === '')) {
			array_pop($args);
		}

		$out = [];
		foreach ($args as $arg) {
			if ($arg === null) {
				$out[] = 'null';
			} else if ($arg instanceof DateTimeInterface) {
				$out[] = $arg->format(DateTimeInterface::ATOM);
			} else if ($arg instanceof IQueryBuilder) {
				$out[] = 'subquery';
			} else if (is_object($arg) === true && method_exists($arg, '__toString') === true) {
				$out[] = (string)$arg;
			} else if (is_array($arg) === true) {
				$parts = [];
				foreach ($arg as $key => $value) {
					$rendered = $this->renderArgs(args: [$value]);
					$parts[] = is_int($key) ? $rendered : ($key . '=' . $rendered);
				}

				$out[] = '[' . implode(', ', $parts) . ']';
			} else if (is_bool($arg) === true) {
				$out[] = ($arg === true) ? 'true' : 'false';
			} else {
				$out[] = (string)$arg;
			}
		}//end foreach

		return implode(', ', $out);
	}//end renderArgs()
}//end trait
