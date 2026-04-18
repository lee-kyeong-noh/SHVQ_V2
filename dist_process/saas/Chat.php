<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/env.php';
require_once __DIR__ . '/../../dist_library/saas/security/init.php';
require_once __DIR__ . '/../../dist_library/saas/GroupwareService.php';

try {
    $security = require __DIR__ . '/../../config/security.php';

    $auth = new AuthService();
    $context = $auth->currentContext();
    if ($context === []) {
        ApiResponse::error('AUTH_REQUIRED', 'authentication required', 401);
        exit;
    }

    $service = new GroupwareService(DbConnection::get(), $security);

    $todo = trim((string)($_POST['todo'] ?? $_GET['todo'] ?? 'room_list'));
    if ($todo === '') {
        $todo = 'room_list';
    }

    $scope = $service->resolveScope(
        $context,
        (string)($_POST['service_code'] ?? $_GET['service_code'] ?? ''),
        (int)($_POST['tenant_id'] ?? $_GET['tenant_id'] ?? 0)
    );

    $requiredTables = $service->requiredTablesByDomain('chat');
    $missingTables = $service->missingTables($requiredTables);
    if ($missingTables !== []) {
        ApiResponse::error('GROUPWARE_SCHEMA_NOT_READY', 'groupware chat tables are not ready', 503, [
            'missing_tables' => $missingTables,
            'required_tables' => $requiredTables,
        ]);
        exit;
    }

    $writeTodos = [
        'room_create',
        'chat_room_create',
        'room_join',
        'chat_room_join',
        'room_leave',
        'chat_room_leave',
        'message_send',
        'chat_send',
        'message_delete',
        'chat_delete',
        'mark_read',
        'chat_read',
    ];

    if (in_array($todo, $writeTodos, true)) {
        $session = new SessionManager($security);
        $csrf = new CsrfService($session, $security);
        if (!$csrf->validateFromRequest()) {
            ApiResponse::error('CSRF_TOKEN_INVALID', 'Invalid CSRF token', 403);
            exit;
        }
    }

    $userPk = (int)($context['user_pk'] ?? 0);
    $roleLevel = (int)($context['role_level'] ?? 0);

    if (in_array($todo, ['room_list', 'chat_rooms'], true)) {
        $rows = $service->listChatRooms($scope, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'items' => $rows,
        ], 'OK', 'chat room list loaded');
        exit;
    }

    if (in_array($todo, ['room_detail', 'chat_room_detail'], true)) {
        $roomId = (int)($_GET['room_idx'] ?? $_GET['room_id'] ?? $_POST['room_idx'] ?? $_POST['room_id'] ?? 0);
        if ($roomId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx is required', 422);
            exit;
        }

        $room = $service->getChatRoomDetail($scope, $roomId, $userPk, $roleLevel);
        if ($room === null) {
            ApiResponse::error('ROOM_NOT_FOUND', 'chat room not found', 404, ['room_idx' => $roomId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'item' => $room,
        ], 'OK', 'chat room loaded');
        exit;
    }

    if (in_array($todo, ['room_create', 'chat_room_create'], true)) {
        $saved = $service->createChatRoom($scope, $userPk, $_POST + $_GET);
        ApiResponse::success([
            'scope' => $scope,
            'item' => $saved,
        ], 'OK', 'chat room created');
        exit;
    }

    if (in_array($todo, ['room_join', 'chat_room_join'], true)) {
        $roomId = (int)($_POST['room_idx'] ?? $_POST['room_id'] ?? $_GET['room_idx'] ?? $_GET['room_id'] ?? 0);
        $targetUserIdx = (int)($_POST['target_user_idx'] ?? $_POST['user_idx'] ?? $_GET['target_user_idx'] ?? $_GET['user_idx'] ?? 0);

        if ($roomId <= 0 || $targetUserIdx <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx and target_user_idx are required', 422);
            exit;
        }

        $ok = $service->joinChatRoom($scope, $roomId, $targetUserIdx, $userPk, $roleLevel);
        if (!$ok) {
            ApiResponse::error('ROOM_NOT_FOUND', 'chat room not found', 404, ['room_idx' => $roomId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'room_idx' => $roomId,
            'target_user_idx' => $targetUserIdx,
        ], 'OK', 'chat room member joined');
        exit;
    }

    if (in_array($todo, ['room_leave', 'chat_room_leave'], true)) {
        $roomId = (int)($_POST['room_idx'] ?? $_POST['room_id'] ?? $_GET['room_idx'] ?? $_GET['room_id'] ?? 0);
        $targetUserIdx = (int)($_POST['target_user_idx'] ?? $_POST['user_idx'] ?? $_GET['target_user_idx'] ?? $_GET['user_idx'] ?? 0);

        if ($roomId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx is required', 422);
            exit;
        }

        $ok = $service->leaveChatRoom($scope, $roomId, $targetUserIdx, $userPk, $roleLevel);
        if (!$ok) {
            ApiResponse::error('CHAT_MEMBER_NOT_FOUND', 'chat room member not found', 404, ['room_idx' => $roomId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'room_idx' => $roomId,
            'target_user_idx' => $targetUserIdx > 0 ? $targetUserIdx : $userPk,
        ], 'OK', 'chat room member left');
        exit;
    }

    if (in_array($todo, ['message_list', 'chat_messages'], true)) {
        $roomId = (int)($_GET['room_idx'] ?? $_GET['room_id'] ?? $_POST['room_idx'] ?? $_POST['room_id'] ?? 0);
        if ($roomId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx is required', 422);
            exit;
        }

        $rows = $service->listChatMessages(
            $scope,
            $roomId,
            $userPk,
            $roleLevel,
            (int)($_GET['last_idx'] ?? $_POST['last_idx'] ?? 0),
            (int)($_GET['limit'] ?? $_POST['limit'] ?? 100)
        );

        ApiResponse::success([
            'scope' => $scope,
            'room_idx' => $roomId,
            'items' => $rows,
        ], 'OK', 'chat message list loaded');
        exit;
    }

    if (in_array($todo, ['message_send', 'chat_send'], true)) {
        $roomId = (int)($_POST['room_idx'] ?? $_POST['room_id'] ?? $_GET['room_idx'] ?? $_GET['room_id'] ?? 0);
        if ($roomId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx is required', 422);
            exit;
        }

        $saved = $service->sendChatMessage($scope, $roomId, $userPk, $roleLevel, $_POST + $_GET);
        ApiResponse::success([
            'scope' => $scope,
            'room_idx' => $roomId,
            'item' => $saved,
        ], 'OK', 'chat message sent');
        exit;
    }

    if (in_array($todo, ['message_delete', 'chat_delete'], true)) {
        $roomId = (int)($_POST['room_idx'] ?? $_POST['room_id'] ?? $_GET['room_idx'] ?? $_GET['room_id'] ?? 0);
        $messageId = (int)($_POST['message_idx'] ?? $_POST['message_id'] ?? $_GET['message_idx'] ?? $_GET['message_id'] ?? 0);

        if ($roomId <= 0 || $messageId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx and message_idx are required', 422);
            exit;
        }

        $ok = $service->deleteChatMessage($scope, $roomId, $messageId, $userPk, $roleLevel);
        if (!$ok) {
            ApiResponse::error('MESSAGE_NOT_FOUND', 'chat message not found', 404, [
                'room_idx' => $roomId,
                'message_idx' => $messageId,
            ]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'room_idx' => $roomId,
            'message_idx' => $messageId,
        ], 'OK', 'chat message deleted');
        exit;
    }

    if (in_array($todo, ['mark_read', 'chat_read'], true)) {
        $roomId = (int)($_POST['room_idx'] ?? $_POST['room_id'] ?? $_GET['room_idx'] ?? $_GET['room_id'] ?? 0);
        if ($roomId <= 0) {
            ApiResponse::error('INVALID_INPUT', 'room_idx is required', 422);
            exit;
        }

        $lastMessageIdx = (int)($_POST['last_message_idx'] ?? $_GET['last_message_idx'] ?? 0);
        $ok = $service->markChatRead($scope, $roomId, $userPk, $roleLevel, $lastMessageIdx);
        if (!$ok) {
            ApiResponse::error('CHAT_MEMBER_NOT_FOUND', 'chat room member not found', 404, ['room_idx' => $roomId]);
            exit;
        }

        ApiResponse::success([
            'scope' => $scope,
            'room_idx' => $roomId,
            'last_message_idx' => $lastMessageIdx,
        ], 'OK', 'chat room read marked');
        exit;
    }

    if (in_array($todo, ['unread_count', 'chat_unread_count'], true)) {
        $count = $service->chatUnreadCount($scope, $userPk);
        ApiResponse::success([
            'scope' => $scope,
            'unread_count' => $count,
        ], 'OK', 'chat unread count loaded');
        exit;
    }

    ApiResponse::error('UNSUPPORTED_TODO', 'unsupported todo', 400, ['todo' => $todo]);
} catch (InvalidArgumentException $e) {
    ApiResponse::error('INVALID_INPUT', $e->getMessage(), 422);
} catch (RuntimeException $e) {
    $message = $e->getMessage();

    if (str_contains($message, 'forbidden') || str_contains($message, 'insufficient role')) {
        ApiResponse::error('FORBIDDEN', $message, 403);
        exit;
    }

    if (str_contains($message, 'not found')) {
        ApiResponse::error('NOT_FOUND', $message, 404);
        exit;
    }

    ApiResponse::error('CONFLICT', $message, 409);
} catch (Throwable $e) {
    ApiResponse::error(
        'SERVER_ERROR',
        shvEnvBool('APP_DEBUG', false) ? $e->getMessage() : 'Internal error',
        500
    );
}
