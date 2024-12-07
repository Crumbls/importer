<?php

namespace Crumbls\Importer\Enums;

/**
 * TODO: Determine if we actually want this or can actually deprecate it.
 */
enum ImportStatus: string
{
	case PENDING = 'pending';
	case ANALYZING = 'analyzing';
	case GENERATING = 'generating';
	case MIGRATING = 'migrating';
	case IMPORTING = 'importing';
	case COMPLETED = 'completed';
	case FAILED = 'failed';
	case PAUSED = 'paused';
}