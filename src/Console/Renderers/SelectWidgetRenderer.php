<?php

declare(strict_types=1);

namespace Crumbls\Importer\Console\Renderers;

use Crumbls\Importer\Console\Widgets\SelectWidget;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Widget\WidgetRenderer;

class SelectWidgetRenderer implements WidgetRenderer
{
	public function render(WidgetRenderer $renderer, Widget $widget, Buffer $buffer, Area $area): void
	{
		if (!$widget instanceof SelectWidget) {
			return;
		}

		// Get the actual widget from SelectWidget and render it
		$actualWidget = $widget->widget();
		$renderer->render($renderer, $actualWidget, $buffer, $area);
	}
}