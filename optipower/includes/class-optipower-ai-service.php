<?php
if (! defined('ABSPATH')) {
	exit;
}

interface OptiPower_AI_Service {
	public function analyze($query, $duration_ms, $context = array());
}

class OptiPower_Rules_AI_Service implements OptiPower_AI_Service {
	public function analyze($query, $duration_ms, $context = array()) {
		return OptiPower_Recommendations::build($query, $duration_ms);
	}
}

