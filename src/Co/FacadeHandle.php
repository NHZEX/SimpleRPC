<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Co;

interface FacadeHandle
{
    /**
     * @param RpcFacadeClass $facade
     * @param array          $argv
     * @return mixed
     */
    public function constructBefore(RpcFacadeClass $facade, array $argv);

    /**
     * @param RpcFacadeClass $facade
     * @return mixed
     */
    public function constructAfter(RpcFacadeClass $facade);
}
