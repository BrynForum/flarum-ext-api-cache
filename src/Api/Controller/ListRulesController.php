<?php

namespace BrynForum\ApiCache\Api\Controller;

use BrynForum\ApiCache\Api\Serializer\RuleSerializer;
use BrynForum\ApiCache\Rule;
use Flarum\Api\Controller\AbstractListController;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface;
use Tobscure\JsonApi\Document;

class ListRulesController extends AbstractListController
{
    public $serializer = RuleSerializer::class;

    protected function data(ServerRequestInterface $request, Document $document)
    {
        RequestUtil::getActor($request)->assertAdmin();

        return Rule::query()
            ->orderBy('priority', 'desc')
            ->orderBy('id', 'asc')
            ->get();
    }
}
