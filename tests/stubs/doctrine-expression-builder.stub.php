<?php

/**
 * Doctrine's ExpressionBuilder operator constants, for unit tests only.
 *
 * `OCP\DB\QueryBuilder\IExpressionBuilder` defines its operator constants
 * from `Doctrine\DBAL\Query\Expression\ExpressionBuilder`, which the
 * `nextcloud/ocp` package does not ship. Without it no test can create a
 * double of the expression builder. The values are Doctrine's own.
 *
 * @category Test
 * @package  OCA\Keepiq\Tests
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

namespace Doctrine\DBAL\Query\Expression;

class ExpressionBuilder {
	public const EQ = '=';
	public const NEQ = '<>';
	public const LT = '<';
	public const LTE = '<=';
	public const GT = '>';
	public const GTE = '>=';
}
