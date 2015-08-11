<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Email\Model\Template;

use Magento\Framework\App\Area;
use Magento\Framework\App\Bootstrap;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\State;
use Magento\Framework\App\TemplateTypesInterface;
use Magento\Framework\Css\PreProcessor\Adapter\Oyejorge;
use Magento\Framework\Phrase;
use Magento\Framework\View\DesignInterface;
use Magento\Setup\Module\I18n\Locale;
use Magento\Store\Model\ScopeInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class FilterTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var \Magento\Email\Model\Template\Filter
     */
    protected $model = null;

    /**
     * @var \Magento\TestFramework\ObjectManager
     */
    protected $objectManager;

    protected function setUp()
    {
        $this->objectManager = \Magento\TestFramework\Helper\Bootstrap::getObjectManager();

        $this->model = $this->objectManager->create(
            'Magento\Email\Model\Template\Filter'
        );
    }

    /**
     * Isolation level has been raised in order to flush themes configuration in-memory cache
     *
     * @magentoAppArea frontend
     */
    public function testViewDirective()
    {
        $url = $this->model->viewDirective(
            ['{{view url="Magento_Theme::favicon.ico"}}', 'view', ' url="Magento_Theme::favicon.ico"']
        );
        $this->assertStringEndsWith('favicon.ico', $url);
    }

    /**
     * Isolation level has been raised in order to flush themes configuration in-memory cache
     *
     * @magentoAppArea frontend
     */
    public function testBlockDirective()
    {
        $class = 'Magento\\\\Theme\\\\Block\\\\Html\\\\Footer';
        $data = ["{{block class='$class' name='test.block' template='Magento_Theme::html/footer.phtml'}}",
                'block',
                " class='$class' name='test.block' template='Magento_Theme::html/footer.phtml'",

            ];
        $html = $this->model->blockDirective($data);
        $this->assertContains('<div class="footer-container">', $html);
    }

    /**
     * @magentoConfigFixture current_store web/unsecure/base_link_url http://example.com/
     * @magentoConfigFixture admin_store web/unsecure/base_link_url http://example.com/
     */
    public function testStoreDirective()
    {
        $url = $this->model->storeDirective(
            ['{{store direct_url="arbitrary_url/"}}', 'store', ' direct_url="arbitrary_url/"']
        );
        $this->assertStringMatchesFormat('http://example.com/%sarbitrary_url/', $url);

        $url = $this->model->storeDirective(
            ['{{store url="translation/ajax/index"}}', 'store', ' url="translation/ajax/index"']
        );
        $this->assertStringMatchesFormat('http://example.com/%stranslation/ajax/index/', $url);

        $this->model->setStoreId(0);
        $backendUrlModel = $this->objectManager->create('Magento\Backend\Model\Url');
        $this->model->setUrlModel($backendUrlModel);
        $url = $this->model->storeDirective(
            ['{{store url="translation/ajax/index"}}', 'store', ' url="translation/ajax/index"']
        );
        $this->assertStringMatchesFormat('http://example.com/index.php/backend/translation/ajax/index/%A', $url);
    }

    /**
     * @magentoDataFixture Magento/Email/Model/_files/design/themes.php
     * @magentoAppIsolation enabled
     * @dataProvider layoutDirectiveDataProvider
     *
     * @param string $area
     * @param string $directiveParams
     * @param string $expectedOutput
     */
    public function testLayoutDirective($area, $directiveParams, $expectedOutput)
    {
        \Magento\TestFramework\Helper\Bootstrap::getInstance()->reinitialize(
            [
                Bootstrap::INIT_PARAM_FILESYSTEM_DIR_PATHS => [
                    DirectoryList::THEMES => [
                        'path' => dirname(__DIR__) . '/_files/design',
                    ],
                ],
            ]
        );
        $this->model = $this->objectManager->create('Magento\Email\Model\Template\Filter');

        $themes = ['frontend' => 'Magento/default', 'adminhtml' => 'Magento/default'];
        $design = $this->objectManager->create('Magento\Theme\Model\View\Design', ['themes' => $themes]);
        $this->objectManager->addSharedInstance($design, 'Magento\Theme\Model\View\Design');

        \Magento\TestFramework\Helper\Bootstrap::getInstance()->loadArea($area);

        $collection = $this->objectManager->create('Magento\Theme\Model\Resource\Theme\Collection');
        $themeId = $collection->getThemeByFullPath('frontend/Magento/default')->getId();
        $this->objectManager->get('Magento\Framework\App\Config\MutableScopeConfigInterface')
            ->setValue(DesignInterface::XML_PATH_THEME_ID, $themeId, ScopeInterface::SCOPE_STORE);

        /** @var $layout \Magento\Framework\View\LayoutInterface */
        $layout = $this->objectManager->create('Magento\Framework\View\Layout');
        $this->objectManager->addSharedInstance($layout, 'Magento\Framework\View\Layout');
        $this->objectManager->get('Magento\Framework\View\DesignInterface')->setDesignTheme('Magento/default');

        $actualOutput = $this->model->layoutDirective(
            ['{{layout ' . $directiveParams . '}}', 'layout', ' ' . $directiveParams]
        );
        $this->assertEquals($expectedOutput, trim($actualOutput));
    }

    /**
     * @return array
     */
    public function layoutDirectiveDataProvider()
    {
        $result = [
            'area parameter - omitted' => [
                'adminhtml',
                'handle="email_template_test_handle"',
                '<b>Email content for frontend/Magento/default theme</b>',
            ],
            'area parameter - frontend' => [
                'adminhtml',
                'handle="email_template_test_handle" area="frontend"',
                '<b>Email content for frontend/Magento/default theme</b>',
            ],
            'area parameter - backend' => [
                'frontend',
                'handle="email_template_test_handle" area="adminhtml"',
                '<b>Email content for adminhtml/Magento/default theme</b>',
            ],
            'custom parameter' => [
                'frontend',
                'handle="email_template_test_handle" template="Magento_Email::sample_email_content_custom.phtml"',
                '<b>Custom Email content for frontend/Magento/default theme</b>',
            ],
        ];
        return $result;
    }

    /**
     * @param $directive
     * @param $translations
     * @param $expectedResult
     * @internal param $translatorData
     * @dataProvider transDirectiveDataProvider
     */
    public function testTransDirective($directive, $translations, $expectedResult)
    {
        $renderer = Phrase::getRenderer();

        $translator = $this->getMockBuilder('\Magento\Framework\Translate')
            ->disableOriginalConstructor()
            ->setMethods(['getData'])
            ->getMock();

        $translator->expects($this->atLeastOnce())
            ->method('getData')
            ->will($this->returnValue($translations));

        $this->objectManager->addSharedInstance($translator, 'Magento\Framework\Translate');
        $this->objectManager->removeSharedInstance('Magento\Framework\Phrase\Renderer\Translate');
        Phrase::setRenderer($this->objectManager->create('Magento\Framework\Phrase\RendererInterface'));

        $this->assertEquals($expectedResult, $this->model->filter($directive));

        Phrase::setRenderer($renderer);
    }

    /**
     * @return array
     */
    public function transDirectiveDataProvider()
    {
        return [
            [
                '{{trans "foobar"}}',
                [],
                'foobar',
            ],
            [
                '{{trans "foobar"}}',
                ['foobar' => 'barfoo'],
                'barfoo',
            ]
        ];
    }

    /**
     * Ensures that the css directive will successfully compile and output contents of a LESS file,
     * as well as supporting loading files from a theme fallback structure.
     *
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Email/Model/_files/design/themes.php
     * @magentoAppIsolation enabled
     * @dataProvider cssDirectiveDataProvider
     *
     * @param int $templateType
     * @param string $directiveParams
     * @param string $expectedOutput
     */
    public function testCssDirective($templateType, $directiveParams, $expectedOutput)
    {
        $this->setUpDesignParams();
        $this->model->setStoreId('fixturestore')
            ->setPlainTemplateMode($templateType == TemplateTypesInterface::TYPE_TEXT);

        $output = $this->model->cssDirective(['{{css ' . $directiveParams . '}}', 'css', ' ' . $directiveParams]);

        if ($expectedOutput !== '') {
            $this->assertContains($expectedOutput, $output);
        } else {
            $this->assertSame($expectedOutput, $output);
        }
    }

    /**
     * @return array
     */
    public function cssDirectiveDataProvider()
    {
        return [
            'CSS from theme' => [
                TemplateTypesInterface::TYPE_HTML,
                'file="css/email-1.css"',
                'color: #111;'
            ],
            'CSS from parent theme' => [
                TemplateTypesInterface::TYPE_HTML,
                'file="css/email-2.css"',
                'color: #222;'
            ],
            'CSS from grandparent theme' => [
                TemplateTypesInterface::TYPE_HTML,
                'file="css/email-3.css"',
                'color: #333;'
            ],
            'Missing file parameter' => [
                TemplateTypesInterface::TYPE_HTML,
                '',
                '/* "file" parameter must be specified */'
            ],
            'Plain-text template outputs nothing' => [
                TemplateTypesInterface::TYPE_TEXT,
                'file="css/email-1.css"',
                '',
            ],
            'Empty or missing file' => [
                TemplateTypesInterface::TYPE_HTML,
                'file="css/non-existent-file.css"',
                '/* Contents of css/non-existent-file.css could not be loaded or is empty */'
            ],
            'File with compilation error results in error message' => [
                TemplateTypesInterface::TYPE_HTML,
                'file="css/file-with-error.css"',
                Oyejorge::ERROR_MESSAGE_PREFIX,
            ],
        ];
    }

    /**
     * Ensures that the inlinecss directive will successfully load and inline CSS to HTML markup,
     * as well as supporting loading files from a theme fallback structure.
     *
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Email/Model/_files/design/themes.php
     * @magentoAppIsolation enabled
     * @dataProvider inlinecssDirectiveDataProvider
     *
     * @param string $templateText
     * @param string $expectedOutput
     * @param bool $productionMode
     * @param bool $plainTemplateMode
     * @param bool $isChildTemplateMode
     */
    public function testInlinecssDirective(
        $templateText,
        $expectedOutput,
        $productionMode = false,
        $plainTemplateMode = false,
        $isChildTemplateMode = false
    ) {
        $this->setUpDesignParams();

        $this->model->setPlainTemplateMode($plainTemplateMode);
        $this->model->setIsChildTemplate($isChildTemplateMode);

        if ($productionMode) {
            $this->objectManager->get('Magento\Framework\App\State')
                ->setMode(State::MODE_PRODUCTION);
        }

        $this->assertContains($expectedOutput, $this->model->filter($templateText));
    }

    /**
     * @return array
     */
    public function inlinecssDirectiveDataProvider()
    {
        return [
            'CSS from theme' => [
                '<html><p></p> {{inlinecss file="css/email-inline-1.css"}}</html>',
                '<p style="color: #111; text-align: left;">',
            ],
            'CSS from parent theme' => [
                '<html><p></p> {{inlinecss file="css/email-inline-2.css"}}</html>',
                '<p style="color: #222; text-align: left;">',
            ],
            'CSS from grandparent theme' => [
                '<html><p></p> {{inlinecss file="css/email-inline-3.css"}}',
                '<p style="color: #333; text-align: left;">',
            ],
            'Non-existent file results in unmodified markup' => [
                '<html><p></p> {{inlinecss file="css/non-existent-file.css"}}</html>',
                '<html><p></p> </html>',
            ],
            'Plain template mode results in unmodified markup' => [
                '<html><p></p> {{inlinecss file="css/email-inline-1.css"}}</html>',
                '<html><p></p> </html>',
                false,
                true,
            ],
            'Child template mode results in unmodified directive' => [
                '<html><p></p> {{inlinecss file="css/email-inline-1.css"}}</html>',
                '<html><p></p> {{inlinecss file="css/email-inline-1.css"}}</html>',
                false,
                false,
                true,
            ],
            'Production mode - File with compilation error results in unmodified markup' => [
                '<html><p></p> {{inlinecss file="css/file-with-error.css"}}</html>',
                '<html><p></p> </html>',
                true,
            ],
            'Developer mode - File with compilation error results in error message' => [
                '<html><p></p> {{inlinecss file="css/file-with-error.css"}}</html>',
                Oyejorge::ERROR_MESSAGE_PREFIX,
                false,
            ],
        ];
    }

    /**
     * @magentoDataFixture Magento/Store/_files/core_fixturestore.php
     * @magentoDataFixture Magento/Email/Model/_files/design/themes.php
     * @magentoAppIsolation enabled
     * @dataProvider inlinecssDirectiveThrowsExceptionWhenMissingParameterDataProvider
     *
     * @param string $templateText
     */
    public function testInlinecssDirectiveThrowsExceptionWhenMissingParameter($templateText)
    {
        $this->setUpDesignParams();

        $this->model->filter($templateText);
    }

    /**
     * @return array
     */
    public function inlinecssDirectiveThrowsExceptionWhenMissingParameterDataProvider()
    {
        return [
            'Missing "file" parameter' => [
                '{{inlinecss}}',
            ],
            'Missing "file" parameter value' => [
                '{{inlinecss file=""}}',
            ],
        ];
    }

    /**
     * Setup the design params
     */
    protected function setUpDesignParams()
    {
        $themeCode = 'Vendor/custom_theme';
        $this->model->setDesignParams([
            'area' => Area::AREA_FRONTEND,
            'theme' => $themeCode,
            'locale' => Locale::DEFAULT_SYSTEM_LOCALE,
        ]);
    }
}
