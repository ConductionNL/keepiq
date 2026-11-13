#!/usr/bin/env php
<?php
/**
 * Generates openapi.json for the versioned Keepiq API (/api/v1).
 *
 * The document is derived from appinfo/routes.php and the controller
 * methods those routes reach: one operation per route under /api/v1, its
 * summary and description from the method's docblock, path parameters from
 * the route, query or body parameters from the method signature, and the
 * HTTP status codes the method body sets itself (Http::STATUS_*). Routes
 * outside /api/v1 (settings, preferences, health, metrics, the SPA) are not
 * part of the versioned API and are left out.
 *
 * Error bodies are described as they are sent today: application/json with
 * a `message` and, on a refusal, an `error` code.
 *
 * Usage: php scripts/generate-openapi.php [--check]
 *   --check  exit 1 when the committed openapi.json differs from the output
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 */

$root = dirname(__DIR__);
$check = in_array('--check', $argv, true);

$routes = (require $root . '/appinfo/routes.php')['routes'];
$info = simplexml_load_file($root . '/appinfo/info.xml');

const PREFIX = '/api/v1';
const STATUS = [
	'OK' => 200, 'CREATED' => 201, 'ACCEPTED' => 202, 'NO_CONTENT' => 204,
	'BAD_REQUEST' => 400, 'UNAUTHORIZED' => 401, 'FORBIDDEN' => 403, 'NOT_FOUND' => 404,
	'METHOD_NOT_ALLOWED' => 405, 'CONFLICT' => 409, 'GONE' => 410, 'PRECONDITION_FAILED' => 412,
	'REQUEST_ENTITY_TOO_LARGE' => 413, 'UNSUPPORTED_MEDIA_TYPE' => 415, 'UNPROCESSABLE_ENTITY' => 422,
	'LOCKED' => 423, 'PRECONDITION_REQUIRED' => 428, 'TOO_MANY_REQUESTS' => 429,
	'INTERNAL_SERVER_ERROR' => 500, 'NOT_IMPLEMENTED' => 501, 'BAD_GATEWAY' => 502,
	'SERVICE_UNAVAILABLE' => 503, 'GATEWAY_TIMEOUT' => 504,
];
const DESCRIPTIONS = [
	200 => 'OK', 201 => 'Created', 202 => 'Accepted', 204 => 'No content',
	400 => 'The request is invalid', 401 => 'Not logged in', 403 => 'Not allowed',
	404 => 'Not found', 405 => 'Method not allowed', 409 => 'Conflict with the current state',
	410 => 'Gone', 412 => 'Precondition failed', 413 => 'Request too large',
	415 => 'Unsupported media type', 422 => 'The request cannot be processed',
	423 => 'Locked', 428 => 'Refused; the body carries an `error` code', 429 => 'Too many requests',
	500 => 'Internal server error', 501 => 'Not implemented', 502 => 'Upstream failure',
	503 => 'Service unavailable', 504 => 'Upstream timeout',
];

/**
 * Parses one controller file into its public methods.
 *
 * @param string $file The controller source file
 *
 * @return array<string,array{doc:string,attrs:string,params:list<array{name:string,type:string,optional:bool}>,body:string}>
 */
function controllerMethods(string $file): array
{
	static $cache = [];
	if (isset($cache[$file]) === true) {
		return $cache[$file];
	}

	$src = (string) file_get_contents($file);
	$methods = [];
	preg_match_all('/public\s+function\s+(\w+)\s*\(/', $src, $m, PREG_OFFSET_CAPTURE);
	foreach ($m[1] as $i => [$name, $offset]) {
		// Docblock and attributes: the text between the previous member's end and this signature.
		$head = substr($src, 0, $m[0][$i][1]);
		$docStart = strrpos($head, '/**');
		$doc = '';
		$attrs = '';
		if ($docStart !== false) {
			$block = substr($head, $docStart);
			$docEnd = strpos($block, '*/');
			$tail = substr($block, $docEnd + 2);
			// Only attributes and whitespace may sit between the docblock and the signature.
			if (preg_match('/[;{}]/', preg_replace('/#\[.*?\]\s*$/ms', '', $tail)) !== 1) {
				$doc = substr($block, 0, $docEnd + 2);
				$attrs = $tail;
			}
		}

		// Signature: balanced parentheses from the opening one.
		$pos = $offset + strlen($name);
		$pos = strpos($src, '(', $pos);
		$depth = 0;
		$sigEnd = $pos;
		for ($j = $pos; $j < strlen($src); $j++) {
			if ($src[$j] === '(') {
				$depth++;
			} elseif ($src[$j] === ')') {
				$depth--;
				if ($depth === 0) {
					$sigEnd = $j;
					break;
				}
			}
		}

		$sig = substr($src, $pos + 1, $sigEnd - $pos - 1);
		$params = [];
		foreach (preg_split('/,(?![^\[(]*[\])])/', $sig) as $part) {
			if (preg_match('/(?:#\[[^\]]*\]\s*)*(?:(?:private|protected|public|readonly)\s+)*([?\w|\\\\]+)?\s*\$(\w+)(\s*=)?/', trim($part), $pm) === 1) {
				$params[] = ['name' => $pm[2], 'type' => ltrim($pm[1], '?'), 'optional' => isset($pm[3]) || str_starts_with($pm[1], '?')];
			}
		}

		// Body: balanced braces from the first one after the signature.
		$open = strpos($src, '{', $sigEnd);
		$depth = 0;
		$bodyEnd = $open;
		for ($j = $open; $j < strlen($src); $j++) {
			if ($src[$j] === '{') {
				$depth++;
			} elseif ($src[$j] === '}') {
				$depth--;
				if ($depth === 0) {
					$bodyEnd = $j;
					break;
				}
			}
		}

		$methods[$name] = ['doc' => $doc, 'attrs' => $attrs, 'params' => $params, 'body' => substr($src, $open, $bodyEnd - $open)];
	}

	return $cache[$file] = $methods;
}

/**
 * The summary (first sentence) and description (the prose before the tags) of a docblock.
 *
 * @param string $doc The docblock
 *
 * @return array{0:string,1:string}
 */
function docText(string $doc): array
{
	$lines = [];
	foreach (preg_split('/\R/', $doc) as $line) {
		$line = preg_replace('#^\s*/?\*+/?\s?#', '', $line);
		if (str_starts_with(ltrim($line), '@') === true) {
			break;
		}

		$lines[] = rtrim($line);
	}

	$text = trim(preg_replace('/\s+/', ' ', implode(' ', $lines)));
	$summary = $text;
	if (preg_match('/^(.+?[.!?])(\s|$)/', $text, $sm) === 1) {
		$summary = $sm[1];
	}

	return [rtrim($summary, '.'), $text];
}

/**
 * JSON-schema type of a PHP parameter type.
 *
 * @param string $type The PHP type
 *
 * @return array<string,mixed>
 */
function schemaOf(string $type): array
{
	$type = strtolower(explode('|', $type)[0]);
	return match ($type) {
		'int' => ['type' => 'integer'],
		'float' => ['type' => 'number'],
		'bool' => ['type' => 'boolean'],
		'array' => ['type' => 'array', 'items' => new stdClass()],
		'string', '' => ['type' => 'string'],
		default => ['type' => 'string'],
	};
}

$paths = [];
$operationIds = [];
foreach ($routes as $route) {
	$url = '/' . ltrim($route['url'], '/');
	if (str_starts_with($url, PREFIX . '/') === false || $url === PREFIX . '/openapi.json') {
		continue;
	}

	$path = substr($url, strlen(PREFIX));
	$verb = strtolower($route['verb'] ?? 'GET');
	[$controller, $action] = explode('#', $route['name']);
	$class = ucfirst($controller) . 'Controller';
	$file = $root . '/lib/Controller/' . $class . '.php';
	$method = controllerMethods($file)[$action] ?? null;
	if ($method === null) {
		fwrite(STDERR, "generate-openapi: {$route['name']} has no method $class::$action\n");
		exit(2);
	}

	[$summary, $description] = docText($method['doc']);
	$public = str_contains($method['attrs'], 'PublicPage');
	// Application routes are public pages to Nextcloud and authenticated by JwtAuthMiddleware.
	$bearer = str_contains((string) file_get_contents($file), 'extends ApplicationApiController');
	$tag = strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $controller));
	$operationId = $tag . '-' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $action));
	if (isset($route['postfix']) === true) {
		$operationId .= '-' . strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $route['postfix']));
	}

	if (isset($operationIds[$operationId]) === true) {
		$operationId .= '-' . $verb;
	}

	$operationIds[$operationId] = true;

	preg_match_all('/\{(\w+)\}/', $path, $pp);
	$pathParams = $pp[1];
	$parameters = [];
	foreach ($pathParams as $name) {
		$type = 'string';
		foreach ($method['params'] as $param) {
			if ($param['name'] === $name) {
				$type = $param['type'];
			}
		}

		$schema = schemaOf($type);
		if (isset($route['requirements'][$name]) === true && $schema['type'] === 'string') {
			$schema['pattern'] = '^' . $route['requirements'][$name] . '$';
		}

		$parameters[] = ['name' => $name, 'in' => 'path', 'required' => true, 'schema' => $schema];
	}

	$fields = [];
	foreach ($method['params'] as $param) {
		if (in_array($param['name'], $pathParams, true) === false) {
			$fields[] = $param;
		}
	}

	$operation = ['operationId' => $operationId, 'summary' => $summary !== '' ? $summary : $route['name']];
	$operation['description'] = $description !== '' ? $description : $operation['summary'] . '.';

	$operation['tags'] = [$tag];
	if (in_array($verb, ['get', 'delete', 'head'], true) === true) {
		foreach ($fields as $field) {
			$parameters[] = ['name' => $field['name'], 'in' => 'query', 'required' => $field['optional'] === false, 'schema' => schemaOf($field['type'])];
		}
	} elseif ($fields !== []) {
		$properties = [];
		$required = [];
		foreach ($fields as $field) {
			$properties[$field['name']] = schemaOf($field['type']);
			if ($field['optional'] === false) {
				$required[] = $field['name'];
			}
		}

		$body = ['type' => 'object', 'properties' => $properties];
		if ($required !== []) {
			$body['required'] = $required;
		}

		$operation['requestBody'] = ['content' => ['application/json' => ['schema' => $body]]];
	}

	if ($parameters !== []) {
		$operation['parameters'] = $parameters;
	}

	$codes = [];
	preg_match_all('/Http::STATUS_([A-Z_]+)/', $method['body'], $sc);
	foreach ($sc[1] as $const) {
		if (isset(STATUS[$const]) === true) {
			$codes[STATUS[$const]] = true;
		}
	}

	if (array_filter(array_keys($codes), static fn (int $c): bool => $c < 300) === []) {
		$codes[200] = true;
	}

	if ($public === false || $bearer === true) {
		$codes[401] = true;
	}

	ksort($codes);
	$responses = [];
	foreach (array_keys($codes) as $code) {
		if ($code < 300) {
			$response = ['description' => DESCRIPTIONS[$code], 'headers' => ['API-Version' => ['$ref' => '#/components/headers/API-Version']]];
			if ($code !== 204) {
				$response['content'] = ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]];
			}

			$responses[(string) $code] = $response;
			continue;
		}

		$responses[(string) $code] = [
			'description' => DESCRIPTIONS[$code] ?? 'Error',
			'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
		];
	}

	$operation['responses'] = $responses;
	if ($bearer === true) {
		$operation['security'] = [['bearerAuth' => []]];
	} elseif ($public === true) {
		$operation['security'] = [];
	}

	$paths[$path][$verb] = $operation;
}

$paths['/openapi.json']['get'] = [
	'operationId' => 'open-api-show',
	'summary' => 'This OpenAPI document',
	'description' => 'The OpenAPI document of the versioned Keepiq API, with info.version set to the installed app version. Public and readable cross-origin.',
	'tags' => ['open-api'],
	'security' => [],
	'responses' => [
		'200' => [
			'description' => 'The OpenAPI document',
			'headers' => [
				'API-Version' => ['$ref' => '#/components/headers/API-Version'],
				'access-control-allow-origin' => ['description' => 'Always `*`.', 'schema' => ['type' => 'string', 'enum' => ['*']]],
			],
			'content' => ['application/json' => ['schema' => ['type' => 'object', 'additionalProperties' => true]]],
		],
		'404' => ['description' => 'The document is not installed', 'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]]],
	],
];
ksort($paths);

$tags = [];
foreach ($paths as $operations) {
	foreach ($operations as $operation) {
		$tags[$operation['tags'][0]] = ['name' => $operation['tags'][0]];
	}
}

ksort($tags);

$version = (string) $info->version;
$document = [
	'openapi' => '3.0.3',
	'info' => [
		'title' => 'Keepiq',
		'version' => $version,
		'description' => 'The versioned JSON API of Keepiq, the password and secrets vault for Nextcloud: secrets, folders, shares, '
			. 'emergency access, recovery, applications and the admin API. Authenticate as a Nextcloud user: a browser session, or an app '
			. 'password over HTTP Basic with the header OCS-APIRequest: true. Generated from appinfo/routes.php by '
			. 'scripts/generate-openapi.php; secret payloads are encrypted on the client and their shape is not described here.',
		'contact' => ['name' => 'Conduction B.V.', 'email' => 'info@conduction.nl', 'url' => 'https://conduction.nl'],
		'license' => ['name' => 'EUPL-1.2', 'url' => 'https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12'],
	],
	'servers' => [[
		'url' => 'https://{host}/index.php/apps/keepiq/api/v1',
		'description' => 'The Keepiq API on a Nextcloud instance.',
		'variables' => ['host' => ['default' => 'localhost', 'description' => 'The host name of the Nextcloud instance.']],
	]],
	'security' => [['basicAuth' => []]],
	'tags' => array_values($tags),
	'paths' => $paths,
	'components' => [
		'securitySchemes' => [
			'basicAuth' => ['type' => 'http', 'scheme' => 'basic', 'description' => 'A Nextcloud user name and app password.'],
			'bearerAuth' => ['type' => 'http', 'scheme' => 'bearer', 'bearerFormat' => 'JWT', 'description' => 'An application token from POST /token, for the /app routes.'],
		],
		'headers' => ['API-Version' => ['description' => 'The full version of the Keepiq app that answered.', 'schema' => ['type' => 'string']]],
		'schemas' => ['Error' => [
			'type' => 'object',
			'properties' => [
				'message' => ['type' => 'string', 'description' => 'A human-readable explanation.'],
				'error' => ['type' => 'string', 'description' => 'A machine-readable code, on a refusal.'],
			],
			'additionalProperties' => true,
		]],
	],
];

$json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
$out = $root . '/openapi.json';
if ($check === true) {
	if (is_file($out) === false || file_get_contents($out) !== $json) {
		fwrite(STDERR, "generate-openapi: openapi.json is stale; run php scripts/generate-openapi.php\n");
		exit(1);
	}

	exit(0);
}

file_put_contents($out, $json);
echo 'openapi.json: ' . count($paths) . ' paths, ' . count($operationIds) + 1 . " operations\n";
