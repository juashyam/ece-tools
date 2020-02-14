<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\MagentoCloud\Test\Functional\Acceptance;

use Magento\CloudDocker\Test\Functional\Codeception\Docker;

/**
 * This test runs on the latest version of PHP
 */
class ElasticSearchCest extends AbstractCest
{
    /**
     * @param \CliTester $I
     */
    public function _before(\CliTester $I): void
    {
        // Do nothing
    }

    /**
     * @param \CliTester $I
     * @param \Codeception\Example $data
     * @throws \Robo\Exception\TaskException
     * @dataProvider elasticDataProvider
     */
    public function testElastic(\CliTester $I, \Codeception\Example $data): void
    {
        $this->prepareWorkplace($I, $data['magento']);

        if ($data['removeES']) {
            $this->removeESIfExists($I);
        }

        $I->runEceDockerCommand('build:compose --mode=production');

        $I->runDockerComposeCommand('run build cloud-build');
        $I->startEnvironment();
        $I->runDockerComposeCommand('run deploy cloud-deploy');

        $I->runDockerComposeCommand(
            'run deploy magento-command config:set general/region/state_required US --lock-env'
        );
        $this->checkConfigurationIsNotRemoved($I);

        $I->amOnPage('/');
        $I->see('Home page');

        $config = $this->getConfig($I);
        $I->assertArraySubset(
            $data['expectedResult'],
            $config['system']['default']['catalog']['search']
        );

        $I->assertTrue($I->cleanDirectories(['/vendor/*', '/setup/*']));
        $I->stopEnvironment(true);
        $this->removeESIfExists($I);

        $I->runEceDockerCommand('build:compose --mode=production');

        $I->runDockerComposeCommand('run build cloud-build');
        $I->startEnvironment();
        $I->runDockerComposeCommand('run deploy cloud-deploy');

        $this->checkConfigurationIsNotRemoved($I);

        $I->amOnPage('/');
        $I->see('Home page');

        $config = $this->getConfig($I);
        $I->assertArraySubset(
            ['engine' => 'mysql'],
            $config['system']['default']['catalog']['search']
        );
    }

    /**
     * @param \CliTester $I
     */
    private function removeESIfExists(\CliTester $I): void
    {
        $services = $I->readServicesYaml();

        if (isset($services['elasticsearch'])) {
            unset($services['elasticsearch']);
            $I->writeServicesYaml($services);

            $app = $I->readAppMagentoYaml();
            unset($app['relationships']['elasticsearch']);
            $I->writeAppMagentoYaml($app);
        }
    }

    /**
     * @param \CliTester $I
     * @return array
     */
    private function getConfig(\CliTester $I): array
    {
        $destination = sys_get_temp_dir() . '/app/etc/env.php';
        $I->assertTrue($I->downloadFromContainer('/app/etc/env.php', $destination, Docker::DEPLOY_CONTAINER));
        return require $destination;
    }

    /**
     * @param \CliTester $I
     * @return array
     */
    private function checkConfigurationIsNotRemoved(\CliTester $I): void
    {
        $config = $this->getConfig($I);

        $I->assertArraySubset(
            ['general' => ['region' => ['state_required' => 'US']]],
            $config['system']['default']
        );
    }

    /**
     * @return array
     */
    protected function elasticDataProvider(): array
    {
        return [
            [
                'magento' => '2.3.4',
                'removeES' => true,
                'expectedResult' => ['engine' => 'mysql'],
            ],
            [
                'magento' => '2.3.4',
                'removeES' => false,
                'expectedResult' => [
                    'engine' => 'elasticsearch6',
                    'elasticsearch6_server_hostname' => 'elasticsearch',
                    'elasticsearch6_server_port' => '9200'
                ],
            ],
        ];
    }
}
