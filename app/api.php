<?php
declare(strict_types=1);

try {
    $route = $_GET['api'] ?? '';
    if (!is_string($route) || !preg_match('/^[a-z]+(?:\/[a-z]+)?$/', $route)) fail('Unknown API route.',404);
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method,['GET','POST'],true)) fail('Method not allowed.',405);
    $input=[];
    if ($method==='POST') {
        if ($route==='upload') $input=$_POST;
        else {
            $raw=file_get_contents('php://input');
            if (strlen($raw)>1048576) fail('Request is too large.',413);
            try { $input=$raw==='' ? [] : json_decode($raw,true,32,JSON_THROW_ON_ERROR); } catch (JsonException) { fail('Request body is not valid JSON.'); }
            if (!is_array($input) || array_is_list($input) && $input!==[]) fail('Expected a JSON object.');
        }
        if (!in_array($route,['print/claim','print/ack'],true)) verify_csrf();
    }
    if (str_starts_with($route,'print/')) {
        if (!function_exists('print_route')) fail('Printing module is unavailable.',503);
        $data=print_route($route,$method,$input);
    } else {
        $data=domain_route($route,$method,$input);
        if ($data===false && function_exists('ops_route')) $data=ops_route($route,$method,$input);
    }
    if ($data===false) fail('Unknown route or unsupported request method.',404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true,'data'=>$data],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (ApiError $error) {
    http_response_code($error->httpStatus); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>$error->getMessage()],JSON_UNESCAPED_UNICODE);
} catch (PDOException $error) {
    error_log('Café POS database error: '.$error->getCode());
    http_response_code(str_contains($error->getMessage(),'locked')?409:500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'The database could not complete this operation. Retry once after checking the result.']);
} catch (Throwable $error) {
    error_log('Café POS error: '.get_class($error).' '.$error->getMessage());
    http_response_code(500); header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>false,'error'=>'The operation could not be completed. Check the local server log.']);
}
exit;
