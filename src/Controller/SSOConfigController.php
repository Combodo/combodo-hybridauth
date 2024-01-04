<?php

namespace Combodo\iTop\HybridAuth\Controller;

use AttributeDateTime;
use CaptureWebPage;
use Combodo\iTop\Application\TwigBase\Controller\Controller;
use Combodo\iTop\Application\TwigBase\Twig\TwigHelper;
use Combodo\iTop\Application\UI\Base\Component\Button\ButtonUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Html\Html;
use Combodo\iTop\Application\UI\Base\Component\Panel\PanelUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Component\Toolbar\ToolbarUIBlockFactory;
use Combodo\iTop\Application\UI\Base\Layout\TabContainer\TabContainer;
use Combodo\iTop\Application\UI\Base\UIException;
use Combodo\iTop\Extension\LDAPConfiguration\Exceptions\ExceptionWithContext;
use Combodo\iTop\Extension\LDAPConfiguration\Repository\LDAP\authentLDAP\AuthentLDAPConfig;
use Combodo\iTop\HybridAuth\Config;
use CoreTemplateException;
use Dict;
use ErrorPage;
use Exception;
use InvalidParameterException;
use IssueLog;
use iTopWebPage;
use Twig\Error\LoaderError;
use utils;

if (!defined('SSO_CONFIG_DIR')) {
	define('SSO_CONFIG_DIR', realpath(dirname(__DIR__, 2)));
}

class SSOConfigController extends Controller
{
    const EXTENSION_NAME = "combodo-hybridauth";
    const TEMPLATE_FOLDER = '/templates';

    private array $aConfig;
	/** @var SSOConfigUtils $oSSOConfigUtils */
    private $oSSOConfigUtils;

    /**
     * @throws InvalidParameterException|Exception
     */
    public function __construct($sViewPath, $sModuleName = 'core')
    {
        try { // try in construct because it can be problem with GetConfigAsArray
            parent::__construct($sViewPath, $sModuleName);
	        $this->oSSOConfigUtils = new SSOConfigUtils();
	        $sSelectedSP = utils::ReadParam('selected_sp', null);
            $this->aConfig = $this->oSSOConfigUtils->GetTwigConfig($sSelectedSP);
	        $this->HidePasswords();
		} catch (Exception|ExceptionWithContext $e) {
            $aContext = method_exists($e, "GetContext") ? $e->getContext() : [];
            IssueLog::Error($e->getMessage(), null, $aContext);
            http_response_code(500);
            $oP = new ErrorPage(Dict::S('UI:PageTitle:FatalError'));
            $oP->add("<h1>" . Dict::S('UI:FatalErrorMessage') . "</h1>\n");
            $oP->add(get_class($e) . ' : ' . htmlentities($e->GetMessage(), ENT_QUOTES, 'utf-8'));
            $oP->output();
        }
    }

	private function HidePasswords(){
		$aProviders = $this->aConfig['providers'];
		foreach ($aProviders as $sProvider => $aConf){
			$sSecret = $aConf['ssoSpSecret'];
			if (strlen($sSecret) !== 0){
				$this->aConfig['providers'][$sProvider]['ssoSpSecret'] = '●●●●●●●●●';
			}
		}
	}

    /**
     * @throws UIException
     * @throws LoaderError
     * @throws CoreTemplateException
     * @throws Exception
     */
    public function OperationMain()
    {
        $oPage = new iTopWebPage(Dict::S('Menu:SSOConfig'));
        $oPage->add_saas('env-' . utils::GetCurrentEnvironment() . '/' . static::EXTENSION_NAME . '/assets/css/combodo-hybridauth.scss');

        $oPanel = PanelUIBlockFactory::MakeForInformation(Dict::S('combodo-hybridauth:MainTitle'));
        $oToolbar = ToolbarUIBlockFactory::MakeStandard();
        $oCancelButton = ButtonUIBlockFactory::MakeForCancel(null, null, null, true, "combodo-hybridauth-cancel");
        $oCancelButton->AddCSSClasses(['action', 'cancel']);
        $oToolbar->AddSubBlock($oCancelButton);
        $oApplyButton = ButtonUIBlockFactory::MakeForPrimaryAction(Dict::S('combodo-hybridauth:Apply'), null, null, true, "combodo-hybridauth-apply");
        $oApplyButton->AddCSSClass('action');
        $oToolbar->AddSubBlock($oApplyButton);
        $oToolbar->AddCSSClass('ibo-toolbar-top');
        $oPanel->AddToolbarBlock($oToolbar);
        $oPage->AddSubBlock($oPanel);

        $oTabContainer = new TabContainer('tabs1', 'sso_config');
        $alert = new Html('<div style="margin-bottom: 50px" id="connectionAlert"></div>');
        $oPanel->AddSubBlock($alert);
        $oPanel->AddMainBlock($oTabContainer);
        $this->aConfig['modulePath'] = utils::GetAbsoluteUrlModulePage(self::EXTENSION_NAME, 'index.php');
	    $sSelectedSp = $this->aConfig['selectedSp'];
	    $this->aConfig['selected_provider_conf'] = $this->aConfig['providers'][$sSelectedSp];

		IssueLog::Info("test", null, [$this->aConfig]);
	    $twigVars = [
			'conf' => $this->aConfig,
			'sso_url' => utils::GetAbsoluteUrlModulePage(self::EXTENSION_NAME, 'index.php'),

	    ];
	    $this->DisplayPage($twigVars, 'sso-main');
    }

	public function OperationSave()
	{
		$aFormData = utils::ReadParam("SSOConfig", null, false, 'raw_data');
		$sSelectedSP = $aFormData['ssoSP'];
		$aProvidersConfig = Config::Get('providers');
		IssueLog::Info('Before OperationSave', null,
			[
				'aProvidersConfig' => $aProvidersConfig,
			]
		);

		$bEnabled = $this->oSSOConfigUtils->GenerateHybridProviderConf($aFormData, $aProvidersConfig, $sSelectedSP);

		Config::SetHybridConfig($aProvidersConfig, $sSelectedSP, $bEnabled);

		@chmod(utils::GetConfig()->GetLoadedFile(), 0770); // Allow overwriting the file
		utils::GetConfig()->WriteToFile();
		@chmod(utils::GetConfig()->GetLoadedFile(), 0440); // Read-only

		/**
		 * $this->DisplayJSONPage([
		 * "code" => 126,
		 * "msg" => Dict::S('combodo-ldap-synchro-configuration:Apply:ErrorWhileWritingFile')
		 * ]);
		 * throw new ExceptionWithContext("Unable to write the config file '$sConfFile'", [
		 * "method" => "OperationApplyConfig"
		 * ]);
		 */

		$this->DisplayJSONPage([
			"code" => 0,
			"msg" => 'OK'
		]);
	}
}
