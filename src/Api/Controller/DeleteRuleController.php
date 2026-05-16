<?php

namespace BrynForum\ApiCache\Api\Controller;

use BrynForum\ApiCache\Rule;
use Flarum\Api\Controller\AbstractDeleteController;
use Flarum\Http\RequestUtil;
use Illuminate\Support\Arr;
use Psr\Http\Message\ServerRequestInterface;

class DeleteRuleController extends AbstractDeleteController
{
    protected function delete(ServerRequestInterface $request)
    {
        RequestUtil::getActor($request)->assertAdmin();

        $id = Arr::get($request->getQueryParams(), 'id');

        Rule::query()->where('id', $id)->delete();
    }
}
