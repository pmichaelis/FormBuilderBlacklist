<?php namespace ProcessWire;

use Fgribreau\MailChecker;

/**
 * FormBuilder Blacklist
 *
 * Checks the content of FormBuilder submissions for spam. Can be enabled per form in the
 * form editor under: Actions > Filter spam > Blacklist & content check.
 *
 * Checks:
 * - Keyword blacklists (regex lists from https://github.com/IQAndreas/php-spam-filter, GPLv3)
 * - Disposable email addresses (fgribreau/mailchecker)
 * - Maximum number of links, BBCode/HTML links, high share of foreign scripts
 *
 * The checks run only when the FormBuilder action "Filter spam" is enabled for the form.
 * What happens with detected spam is controlled by FormBuilder's "What should happen when
 * spam is detected?" setting.
 *
 * Dependencies are installed module-local: run "composer install" in this directory.
 *
 * @author Philipp Michaelis (pmichaelis)
 * @license MIT
 * @link https://github.com/pmichaelis/FormBuilderBlacklist
 *
 */
class FormBuilderBlacklist extends WireData implements Module {

	public static function getModuleInfo() {
		return array(
			'title' => __('FormBuilder Blacklist', __FILE__),
			'summary' => __('Checks the content of FormBuilder submissions against blacklists and spam heuristics, can be enabled per form under Actions > Filter spam.', __FILE__),
			'version' => '1.0.0',
			'author' => 'Philipp Michaelis (pmichaelis)',
			'href' => 'https://github.com/pmichaelis/FormBuilderBlacklist',
			'icon' => 'ban',
			'autoload' => true,
			'singular' => true,
			'requires' => array('PHP>=8.1.0', 'ProcessWire>=3.0.200', 'FormBuilder'),
		);
	}

	/**
	 * Name of the property the settings are stored in on the FormBuilderForm
	 *
	 */
	const settingsKey = 'FormBuilderBlacklist';

	/**
	 * Prefix for inputfield names in the form editor
	 *
	 */
	const prefix = 'fbbl_';

	/**
	 * Spam type name passed to FormBuilder when spam is detected
	 *
	 */
	const spamType = 'blacklist';

	/**
	 * Blacklists that contain URLs/fragments and must not be wrapped in word boundaries
	 *
	 */
	protected $rawLists = array('injection', 'misc', 'urls');

	/**
	 * Loaded blacklist patterns indexed by list name
	 *
	 * @var array
	 *
	 */
	protected $patterns = array();

	public function init() {
		$this->addHookAfter('FormBuilderProcessor::processInputIsSpam', $this, 'hookProcessInputIsSpam');
		$this->addHookAfter('ProcessFormBuilder::buildEditForm', $this, 'hookBuildEditForm');
		$this->addHookAfter('ProcessFormBuilder::executeSaveFormSettings', $this, 'hookSaveFormSettings');
	}

	/**
	 * Load composer dependencies
	 *
	 */
	protected function loadVendor() {
		$file = __DIR__ . '/vendor/autoload.php';
		if(!is_file($file)) {
			throw new WireException($this->_('FormBuilderBlacklist: Dependencies are missing, please run "composer install" in the module directory.'));
		}
		require_once($file);
	}

	/**
	 * Default settings for a form
	 *
	 * @return array
	 *
	 */
	public function getDefaults() {
		return array(
			'enabled' => 0,
			'lists' => array_keys($this->getListNames()),
			'fields' => array(),
			'maxLinks' => 2,
			'disposableEmail' => 1,
			'blockMarkup' => 1,
			'foreignScripts' => 1,
			'log' => 1,
		);
	}

	/**
	 * Get settings for given form merged with defaults
	 *
	 * @param FormBuilderForm $fbForm
	 * @return array
	 *
	 */
	public function getSettings(FormBuilderForm $fbForm) {
		$settings = $fbForm->get(self::settingsKey);
		if(!is_array($settings)) $settings = array();
		return array_merge($this->getDefaults(), $settings);
	}

	/**
	 * Is the filter active for the given processor?
	 *
	 * @param FormBuilderProcessor $processor
	 * @return array|false Settings when active, false when not
	 *
	 */
	protected function getActiveSettings(FormBuilderProcessor $processor) {
		if(!($processor->saveFlags & FormBuilderProcessor::saveFlagFilterSpam)) return false;
		$fbForm = $processor->getFbForm();
		if(!$fbForm) return false;
		$settings = $this->getSettings($fbForm);
		return empty($settings['enabled']) ? false : $settings;
	}

	/*** FRONTEND ***********************************************************************/

	/**
	 * Run checks after FormBuilder's own spam checks
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookProcessInputIsSpam(HookEvent $event) {
		if($event->return) return; // already detected as spam
		$processed = $event->arguments(1);
		if(!$processed) return; // only check once input has been processed

		/** @var FormBuilderProcessor $processor */
		$processor = $event->object;
		/** @var InputfieldForm $form */
		$form = $event->arguments(0);
		$settings = $this->getActiveSettings($processor);
		if(!$settings) return;

		$reason = $this->detect($form, $settings);
		if(!$reason) return;

		if(!empty($settings['log'])) {
			$this->wire()->log->save('form-builder-blacklist', $processor->getFbForm()->name . ": $reason");
		}

		$processor->processInputSpammed($form, self::spamType);
		$event->return = self::spamType;
	}

	/**
	 * Check processed form values for spam
	 *
	 * @param InputfieldForm $form
	 * @param array $settings
	 * @return string Reason when spam detected, blank string otherwise
	 *
	 */
	public function detect(InputfieldForm $form, array $settings) {

		$fieldNames = is_array($settings['fields']) ? $settings['fields'] : array();

		if(!empty($settings['disposableEmail'])) {
			$this->loadVendor();
			foreach($form->getAll() as $f) {
				if(!$f instanceof InputfieldEmail) continue;
				$email = (string) $f->val();
				if($email !== '' && MailChecker::isBlacklisted($email)) return sprintf($this->_('Disposable email address (%s)'), $email);
			}
		}

		$texts = $this->getTexts($form, $fieldNames);
		if(!count($texts)) return '';
		$text = implode("\n", $texts);

		if($settings['maxLinks'] !== '' && $settings['maxLinks'] !== null) {
			$numLinks = preg_match_all('~(?:https?://|www\.)~i', $text);
			if($numLinks > (int) $settings['maxLinks']) return sprintf($this->_('Too many links (%d)'), $numLinks);
		}

		if(!empty($settings['blockMarkup'])) {
			if(preg_match('~\[(?:url|link)\b|<a\s[^>]*href~i', $text)) return $this->_('BBCode/HTML link');
		}

		if(!empty($settings['foreignScripts'])) {
			$numForeign = preg_match_all('/[\p{Cyrillic}\p{Han}\p{Hangul}\p{Hiragana}\p{Katakana}\p{Arabic}\p{Thai}]/u', $text);
			$numLetters = preg_match_all('/\p{L}/u', $text);
			if($numForeign >= 10 && $numForeign / max(1, $numLetters) > 0.3) return $this->_('Foreign script');
		}

		if(is_array($settings['lists']) && count($settings['lists'])) {
			foreach($texts as $value) {
				$match = $this->matchBlacklists($value, $settings['lists']);
				if($match) return $match;
			}
		}

		return '';
	}

	/**
	 * Get text values from the processed form
	 *
	 * @param InputfieldForm $form
	 * @param array $fieldNames Limit to these field names (empty for all)
	 * @return array
	 *
	 */
	protected function getTexts(InputfieldForm $form, array $fieldNames) {
		$texts = array();
		foreach($form->getAll() as $f) {
			/** @var Inputfield $f */
			if(count($fieldNames) && !in_array($f->attr('name'), $fieldNames)) continue;
			// option values are defined by the admin, not by the submitter
			if($f instanceof InputfieldSelect || $f instanceof InputfieldCheckbox) continue;
			if($f instanceof InputfieldSubmit || $f instanceof InputfieldFile) continue;
			$value = $f->val();
			$values = is_array($value) ? $value : array($value);
			foreach($values as $v) {
				if(is_string($v) && trim($v) !== '') $texts[] = $v;
			}
		}
		return $texts;
	}

	/*** BLACKLISTS *********************************************************************/

	/**
	 * Get available blacklists as [name => label]
	 *
	 * @return array
	 *
	 */
	public function getListNames() {
		$labels = array(
			'medication' => $this->_('Medication / potency drugs'),
			'gambling' => $this->_('Gambling / casino'),
			'merchandise' => $this->_('Counterfeit brands / shops'),
			'services' => $this->_('Loans / services'),
			'trading' => $this->_('Trading / binary options'),
			'software' => $this->_('Software / keys / gaming gold'),
			'hosting' => $this->_('Hosting / VPN'),
			'polish' => $this->_('Polish SEO spam'),
			'injection' => $this->_('Spam tool placeholders (e.g. %BLOGTITLE%)'),
			'misc' => $this->_('Miscellaneous (link patterns)'),
			'urls' => $this->_('Known spam domains'),
		);
		$names = array();
		foreach($this->getListFiles() as $name => $file) {
			$names[$name] = isset($labels[$name]) ? $labels[$name] : $name;
		}
		return $names;
	}

	/**
	 * Get blacklist files from the index as [name => file]
	 *
	 * @return array
	 *
	 */
	protected function getListFiles() {
		$dir = __DIR__ . '/blacklists/';
		$files = array();
		if(!is_file($dir . 'index')) return $files;
		foreach(file($dir . 'index', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $file) {
			$file = basename(trim($file));
			if(!is_file($dir . $file)) continue;
			$name = preg_replace('/^blacklist-|\.txt$/', '', $file);
			$files[$name] = $dir . $file;
		}
		return $files;
	}

	/**
	 * Get compiled patterns for a blacklist
	 *
	 * @param string $name
	 * @return array
	 *
	 */
	protected function getPatterns($name) {
		if(isset($this->patterns[$name])) return $this->patterns[$name];
		$files = $this->getListFiles();
		$patterns = array();
		if(isset($files[$name])) {
			$wordBoundaries = !in_array($name, $this->rawLists);
			foreach(file($files[$name]) as $line) {
				// same format as php-spam-filter: one regex per line, '#' starts a comment
				$regex = preg_replace('/(^\s+|\s+$|\s*#.*$)/', '', $line);
				if($regex === '') continue;
				$regex = str_replace('~', '\~', $regex);
				// word boundaries prevent false positives like "forex" in "Forexample"
				if($wordBoundaries) $regex = "(?<![\p{L}\p{N}_])(?:$regex)(?![\p{L}\p{N}_])";
				$patterns[] = "~$regex~iu";
			}
		}
		$this->patterns[$name] = $patterns;
		return $patterns;
	}

	/**
	 * Match text against the given blacklists
	 *
	 * @param string $text
	 * @param array $lists
	 * @return string Reason when matched, blank string otherwise
	 *
	 */
	public function matchBlacklists($text, array $lists) {
		foreach($lists as $name) {
			foreach($this->getPatterns($name) as $pattern) {
				if(@preg_match($pattern, $text, $matches)) {
					return sprintf($this->_('Blacklist %1$s: "%2$s"'), $name, $matches[0]);
				}
			}
		}
		return '';
	}

	/*** ADMIN **************************************************************************/

	/**
	 * Add settings to the "Filter spam" fieldset in the form editor (Actions tab)
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookBuildEditForm(HookEvent $event) {
		/** @var InputfieldForm $editForm */
		$editForm = $event->return;
		$fieldsetSpam = null;
		foreach($editForm->getAll(array('withWrappers' => true)) as $f) {
			if($f->attr('id') !== 'fieldsetSpam') continue;
			$fieldsetSpam = $f;
			break;
		}
		if(!$fieldsetSpam instanceof InputfieldWrapper) return;

		/** @var FormBuilder $forms */
		$forms = $this->wire('forms');
		$fbForm = $forms->load((int) $event->arguments(0));
		if(!$fbForm instanceof FormBuilderForm) return;

		$modules = $this->wire()->modules;
		$settings = $this->getSettings($fbForm);
		$p = self::prefix;
		$showIf = "{$p}enabled=1";

		/** @var InputfieldFieldset $fs */
		$fs = $modules->get('InputfieldFieldset');
		$fs->attr('id+name', "{$p}fieldset");
		$fs->label = $this->_('Blacklist & content check');
		$fs->icon = 'ban';
		$fs->collapsed = empty($settings['enabled']) ? Inputfield::collapsedYes : Inputfield::collapsedNo;
		$fieldsetSpam->add($fs);

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', "{$p}enabled");
		$f->label = $this->_('Enable blacklist & content check');
		$f->label2 = $this->_('Check submissions with the checks below');
		$f->description = $this->_('Complements the spam filters built into FormBuilder. When spam is detected, the setting “What should happen when spam is detected?” above applies.');
		if(!empty($settings['enabled'])) $f->attr('checked', 'checked');
		$fs->add($f);

		/** @var InputfieldCheckboxes $f */
		$f = $modules->get('InputfieldCheckboxes');
		$f->attr('name', "{$p}lists");
		$f->label = $this->_('Blacklists');
		$f->description = $this->_('Keyword lists from php-spam-filter (mostly English spam). Keywords only match as whole words.');
		$f->notes = sprintf($this->_('List files: %s'), 'site/modules/FormBuilderBlacklist/blacklists/');
		$f->optionColumns = 2;
		foreach($this->getListNames() as $name => $label) $f->addOption($name, $label);
		$f->attr('value', is_array($settings['lists']) ? $settings['lists'] : array());
		$f->showIf = $showIf;
		$fs->add($f);

		/** @var InputfieldAsmSelect $f */
		$f = $modules->get('InputfieldAsmSelect');
		$f->attr('name', "{$p}fields");
		$f->label = $this->_('Fields to check');
		$f->description = $this->_('Leave blank to check all text fields.');
		$skipTypes = array('Fieldset', 'FieldsetOpen', 'FieldsetClose', 'FieldsetPage', 'FormBuilderPageBreak', 'Submit', 'Markup', 'FormBuilderFile');
		foreach($fbForm->getChildrenFlat() as $field) {
			if(in_array($field->type, $skipTypes)) continue;
			$f->addOption($field->name, $field->label ? "$field->label ($field->name)" : $field->name);
		}
		$f->attr('value', is_array($settings['fields']) ? $settings['fields'] : array());
		$f->collapsed = Inputfield::collapsedBlank;
		$f->showIf = $showIf;
		$fs->add($f);

		/** @var InputfieldInteger $f */
		$f = $modules->get('InputfieldInteger');
		$f->attr('name', "{$p}maxLinks");
		$f->label = $this->_('Maximum number of links');
		$f->description = $this->_('More links (http://, https://, www.) are considered spam. 0 = no links allowed, blank = no check.');
		$f->attr('value', $settings['maxLinks']);
		$f->columnWidth = 25;
		$f->showIf = $showIf;
		$fs->add($f);

		$checkboxes = array(
			'disposableEmail' => array(
				$this->_('Block disposable email addresses'),
				$this->_('Email fields containing addresses of disposable email services (e.g. mailinator.com) are considered spam.'),
			),
			'blockMarkup' => array(
				$this->_('Block BBCode and HTML links'),
				$this->_('Submissions containing [url=…], [link…] or <a href…> are considered spam.'),
			),
			'foreignScripts' => array(
				$this->_('Block foreign scripts'),
				$this->_('Texts consisting mainly of Cyrillic, Chinese, Japanese, Korean, Arabic or Thai characters are considered spam.'),
			),
		);
		foreach($checkboxes as $name => $labels) {
			/** @var InputfieldCheckbox $f */
			$f = $modules->get('InputfieldCheckbox');
			$f->attr('name', $p . $name);
			$f->label = $labels[0];
			$f->description = $labels[1];
			if(!empty($settings[$name])) $f->attr('checked', 'checked');
			$f->columnWidth = 25;
			$f->showIf = $showIf;
			$fs->add($f);
		}

		/** @var InputfieldCheckbox $f */
		$f = $modules->get('InputfieldCheckbox');
		$f->attr('name', "{$p}log");
		$f->label = $this->_('Log detected spam');
		$f->description = $this->_('Writes form and reason to Setup > Logs > form-builder-blacklist.');
		if(!empty($settings['log'])) $f->attr('checked', 'checked');
		$f->showIf = $showIf;
		$fs->add($f);
	}

	/**
	 * Save settings from the form editor
	 *
	 * @param HookEvent $event
	 *
	 */
	public function hookSaveFormSettings(HookEvent $event) {
		/** @var FormBuilderForm $fbForm */
		$fbForm = $event->arguments(0);
		/** @var InputfieldForm $editForm */
		$editForm = $event->arguments(1);
		$p = self::prefix;
		if(!$editForm->getChildByName("{$p}enabled")) return;

		$settings = array();
		foreach(array('enabled', 'disposableEmail', 'blockMarkup', 'foreignScripts', 'log') as $name) {
			$settings[$name] = $editForm->getChildByName($p . $name)->attr('checked') ? 1 : 0;
		}
		foreach(array('lists', 'fields') as $name) {
			$value = $editForm->getChildByName($p . $name)->attr('value');
			$settings[$name] = is_array($value) ? array_values($value) : array();
		}
		$maxLinks = $editForm->getChildByName("{$p}maxLinks")->attr('value');
		$settings['maxLinks'] = $maxLinks === '' || $maxLinks === null ? '' : max(0, (int) $maxLinks);

		$fbForm->set(self::settingsKey, $settings);
	}
}
