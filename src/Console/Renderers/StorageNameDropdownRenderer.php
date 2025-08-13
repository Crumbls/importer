<?php

declare(strict_types=1);

namespace Crumbls\Importer\Console\Renderers;

use Crumbls\Importer\Console\Widgets\StorageNameDropdownWidget;
use Illuminate\Support\Facades\Log;
use PhpTui\Tui\Display\Area;
use PhpTui\Tui\Display\Buffer;
use PhpTui\Tui\Layout\Layout;
use PhpTui\Tui\Widget\Widget;
use PhpTui\Tui\Widget\WidgetRenderer;
use RuntimeException;

final class StorageNameDropdownRenderer implements WidgetRenderer
{
	public function render(WidgetRenderer $renderer, Widget $widget, Buffer $buffer, Area $area): void
	{
		if (!$widget instanceof StorageNameDropdownWidget) {
			return;
		}

		Log::info(get_class_methods($widget));

		$layout = Layout::default()
			->constraints($widget->getConstraints())
			->direction($widget->direction)
			->split($area);


		foreach ($widget->widgets as $index => $gridWidget) {
			if (!$layout->has($index)) {
				throw new RuntimeException(sprintf(
					'Widget at offset %d has no corresponding constraint. ' .
					'Ensure that the number of constraints match or exceed the number of widgets',
					$index
				));
			}
			$cellArea = $layout->get($index);
			$subBuffer = Buffer::empty($cellArea);
			$renderer->render($renderer, $gridWidget, $subBuffer, $subBuffer->area());
			$buffer->putBuffer($cellArea->position, $subBuffer);
		}
	}
}