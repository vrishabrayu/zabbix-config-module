<?php
declare(strict_types = 1);

namespace Modules\ConfigManager;

use Zabbix\Core\CModule;
use APP;
use CMenuItem;

class Module extends CModule {

	public function init(): void {
		APP::Component()->get('menu.main')
			->findOrAdd(_('Configuration'))
				->getSubmenu()
				->add((new CMenuItem(_('Config Manager')))
					->setAction('configmanager.view')
				);
	}
}
