<?php
/**
 * TEMPORARY GATE-7 PROBE — not for merge.
 *
 * @category Controller
 * @package  OCA\Doriath\Controller
 * @author   Conduction <info@conduction.nl>
 * @license  EUPL-1.2
 * @link     https://github.com/ConductionNL/doriath
 */

declare(strict_types=1);

namespace OCA\Doriath\Controller;

use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Probe controller.
 */
class ZzGate7ProbeController extends Controller
{

    /**
     * Constructor.
     *
     * @param string   $appName The app name
     * @param IRequest $request The request
     */
    public function __construct(string $appName, IRequest $request)
    {
        parent::__construct($appName, $request);
    }//end __construct()

    /**
     * Fetch an arbitrary secret by id with no authorisation whatsoever.
     *
     * @param string $id The secret id
     *
     * @return JSONResponse
     */
    #[NoAdminRequired]
    public function showProbe(string $id): JSONResponse
    {
        $userId = $this->userSession->getUser()?->getUID();
        if ($userId === null) {
            return new JSONResponse(data: ['message' => 'Unauthorized'], statusCode: 401);
        }

        $row = $this->secretMapper->find($id);
        return new JSONResponse(data: $row->jsonSerialize());
    }//end showProbe()
}//end class
