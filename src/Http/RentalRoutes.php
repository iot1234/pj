<?php
declare(strict_types=1);

namespace Dormitory\Http;

use Dormitory\Application;
use Dormitory\Domain\StayOverviewService;
use Dormitory\Support\Validator;

/** Separate inventory and reports; shared security still protects both streams. */
final class RentalRoutes
{
    public static function register(Router $router, Application $app): void
    {
        $owner = ['auth' => 'admin', 'role' => 'owner'];
        foreach (['monthly', 'daily'] as $mode) {
            $base = '/api/admin/' . $mode;
            $router->get($base . '/rooms', static function (Request $r) use ($app, $mode): Response {
                Validator::only($r->query, []);
                return Response::json($app->rooms()->all($mode));
            }, $owner);
            $router->post($base . '/rooms', static function (Request $r) use ($app, $mode): Response {
                $data = $app->database()->transaction(static function () use ($app, $r, $mode): array {
                    $room = $app->rooms()->create($r->body, $mode);
                    $app->audit()->writeStrict($r, $app->actor(), 'room.create', 'room', $room['id'], ['rental_mode' => $mode]);
                    return $room;
                });
                return Response::json($data, 201);
            }, $owner);
            $router->put($base . '/rooms/{id}', static function (Request $r) use ($app, $mode): Response {
                $id = Validator::id($r->param('id'), 'id');
                Validator::string($r->body['expected_version'] ?? null, 'expected_version', 64, 64);
                $data = $app->database()->transaction(static function () use ($app, $r, $mode, $id): array {
                    $room = $app->rooms()->update($id, $r->body, $mode);
                    $app->audit()->writeStrict($r, $app->actor(), 'room.update', 'room', $room['id'], ['rental_mode' => $mode]);
                    return $room;
                });
                return Response::json($data);
            }, $owner);
            $router->delete($base . '/rooms/{id}', static function (Request $r) use ($app, $mode): Response {
                Validator::only($r->body, []);
                $id = Validator::id($r->param('id'), 'id');
                $app->database()->transaction(static function () use ($app, $r, $mode, $id): void {
                    $app->rooms()->delete($id, $mode);
                    $app->audit()->writeStrict($r, $app->actor(), 'room.delete', 'room', $id, ['rental_mode' => $mode]);
                });
                return Response::json(null, 200, 'Room deleted');
            }, $owner);
            $router->get($base . '/revenue', static function (Request $r) use ($app, $mode): Response {
                Validator::only($r->query, ['period', 'offset']);
                $period = Validator::string($r->query['period'] ?? null, 'period', 7, 7);
                $rawOffset = $r->query['offset'] ?? '0';
                if (!is_string($rawOffset) || !preg_match('/^(?:0|[1-9][0-9]{0,8})$/D', $rawOffset)) {
                    throw new HttpException(422, 'Invalid offset', 'VALIDATION_ERROR', ['field' => 'offset']);
                }
                return Response::json($app->revenue()->{$mode}($period, (int) $app->actor()['id'], (int) $rawOffset));
            }, $owner);
        }
        $router->get('/api/admin/daily/overview', static function (Request $r) use ($app): Response {
            Validator::only($r->query, []);
            return Response::json((new StayOverviewService($app))->daily((int) $app->actor()['id']));
        }, $owner);
    }
}
