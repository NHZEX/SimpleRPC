<?php
declare(strict_types=1);

namespace HZEX\SimpleRpc\Contract;

interface FacadeHandleInterface
{
    /**
     * @param FacadeInterface $facade
     * @param array          $argv
     * @return mixed
     */
    public function constructBefore(FacadeInterface $facade, array $argv);

    /**
     * @param FacadeInterface $facade
     * @return mixed
     */
    public function constructAfter(FacadeInterface $facade);
}
