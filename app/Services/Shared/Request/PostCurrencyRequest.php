<?php

declare(strict_types=1);

namespace App\Services\Shared\Request;

use GrumpyDictator\FFIIIApiSupport\Request\Request;
use GrumpyDictator\FFIIIApiSupport\Response\GetCurrencyResponse;
use GrumpyDictator\FFIIIApiSupport\Response\Response;
use GrumpyDictator\FFIIIApiSupport\Response\ValidationErrorResponse;

class PostCurrencyRequest extends Request
{
    public function __construct(string $url, string $token)
    {
        $this->setBase($url);
        $this->setToken($token);
        $this->setUri('currencies');
    }

    public function get(): Response {}

    public function post(): Response
    {
        $data = $this->authenticatedPost();

        if (array_key_exists('errors', $data) && is_array($data['errors'])) {
            return new ValidationErrorResponse($data['errors']);
        }
        if (!array_key_exists('data', $data) || !is_array($data['data'])) {
            $info = ['unknown_field' => [sprintf('Unknown error: %s', json_encode($data, 0, 16))]];

            return new ValidationErrorResponse($info);
        }

        return new GetCurrencyResponse($data['data']);
    }

    public function put(): Response {}

    public function delete(): Response {}
}

