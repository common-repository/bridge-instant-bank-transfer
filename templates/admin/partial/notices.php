<?php
(defined('ABSPATH') && !empty($notice)) || exit;
?>

<div class="notice <?php echo esc_attr($notice['class']); ?>">
	<p><?php echo esc_html($notice['message']); ?></p>
</div>