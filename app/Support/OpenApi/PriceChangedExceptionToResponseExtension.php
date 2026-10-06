<?php

declare(strict_types=1);

namespace App\Support\OpenApi;

use App\Exceptions\PriceChangedException;
use Dedoc\Scramble\Extensions\ExceptionToResponseExtension;
use Dedoc\Scramble\Support\Generator\Reference;
use Dedoc\Scramble\Support\Generator\Response;
use Dedoc\Scramble\Support\Generator\Schema;
use Dedoc\Scramble\Support\Generator\Types as OpenApiTypes;
use Dedoc\Scramble\Support\Type\ObjectType;
use Dedoc\Scramble\Support\Type\Type;

final class PriceChangedExceptionToResponseExtension extends ExceptionToResponseExtension
{
    public function shouldHandle(Type $type): bool
    {
        return $type instanceof ObjectType
            && $type->isInstanceOf(PriceChangedException::class);
    }

    public function toResponse(Type $type): Response
    {
        $body = (new OpenApiTypes\ObjectType)
            ->addProperty('message', new OpenApiTypes\StringType)
            ->addProperty('quote', new Reference('schemas', 'StayRoomsQuoteResource', $this->components))
            ->setRequired(['message', 'quote']);

        return Response::make(409)
            ->setDescription('The price changed')
            ->setContent('application/json', Schema::fromType($body));
    }

    public function reference(ObjectType $type): Reference
    {
        return new Reference('responses', PriceChangedException::class, $this->components);
    }
}
