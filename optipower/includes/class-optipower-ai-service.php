<?php
if (! defined('ABSPATH')) {
	exit;
}

interface OptiPower_AI_Service {
	public function analyze($query, $duration_ms);
}

class OptiPower_Rules_AI_Service implements OptiPower_AI_Service {
	public function analyze($query, $duration_ms) {
		return OptiPower_Recommendations::build($query, $duration_ms);
	}
}

