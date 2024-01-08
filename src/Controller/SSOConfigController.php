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
use Combodo\iTop\HybridAuth\Config;
use CoreTemplateException;
use Dict;
use ErrorPage;
use Exception;
use InvalidParameterException;
use IssueLog;
use iTopWebPage;
use Twig\Error\LoaderError;
use utils;;
use UserRights;

if (!defined('SSO_CONFIG_DIR')) {
	define('SSO_CONFIG_DIR', realpath(dirname(__DIR__, 2)));
}

class SSOConfigController extends Controller
{
    const EXTENSION_NAME = "combodo-hybridauth";
    const LOG_CHANNEL = "Hybridauth";
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
			$aProposedSpList = Config::GetProposedSpList();
	        $this->oSSOConfigUtils = new SSOConfigUtils($aProposedSpList);
	        $sSelectedSP = utils::ReadParam('selected_sp', null);
            $this->aConfig = $this->oSSOConfigUtils->GetTwigConfig($sSelectedSP);
	        $this->HidePasswords();
		} catch (Exception|ExceptionWithContext $e) {
            $aContext = method_exists($e, "GetContext") ? $e->getContext() : [];
            IssueLog::Error($e->getMessage(), self::LOG_CHANNEL, $aContext);
            http_response_code(500);
            $oP = new ErrorPage(Dict::S('UI:PageTitle:FatalError'));
            $oP->add("<h1>" . Dict::S('UI:FatalErrorMessage') . "</h1>\n");
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

    public function OperationMain()
    {
        $this->aConfig['modulePath'] = utils::GetAbsoluteUrlModulePage(self::EXTENSION_NAME, 'index.php');

		$sSelectedSp = $this->aConfig['selectedSp'] ?? null;
		if (! is_null($sSelectedSp) && array_key_exists($sSelectedSp, $this->aConfig['providers'])){
			$this->aConfig['selected_provider_conf'] = $this->aConfig['providers'][$sSelectedSp];
		}

	    $sMsg = \Dict::Format('combodo-hybridauth:LandingUrlMessage', utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/landing.php');
		IssueLog::Info($sMsg);
	    $twigVars = [
			'conf' => $this->aConfig,
			'sso_url' => utils::GetAbsoluteUrlModulePage(self::EXTENSION_NAME, 'index.php'),
			'landing_url_msg' => $sMsg,
			'test_sso_url' => utils::GetAbsoluteUrlModulesRoot().'combodo-hybridauth/test-sso.php',
	    ];
	    $this->DisplayPage($twigVars, 'sso-main');
    }

	public function OperationSave()
	{
		$aFormData = utils::ReadParam("SSOConfig", null, false, 'raw_data');
		$sSelectedSP = $aFormData['ssoSP'];
		$aProvidersConfig = Config::Get('providers');

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
