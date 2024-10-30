<?php
defined('ABSPATH') || exit;
?>

<section>
	<div style="text-align: center;">
		<h1><?php _e('Accept your first payments in 10 minutes', 'bridgeapi-io');?></h1>
	</div>
	<div style="background: #fff; padding: 20px; width: 40%;">
		<strong><?php _e('Test Bridge', 'bridgeapi-io')?></strong>
		<ul style="list-style: square; margin-left: 30px;">
			<li>
				<a href="https://dashboard.bridgeapi.io/signup?utm_campaign=connector_woocommerce" target="_blank">
					<?php _e('Create an account', 'bridgeapi-io');?>
				</a>
			</li>
			<li><?php _e('Create a sandbox application', 'bridgeapi-io');?></li>
			<li><?php _e('Enable test mode below', 'bridgeapi-io');?></li>
			<li><?php _e('Plug-in sandbox client ID and client Secret below', 'bridgeapi-io');?></li>
			<li>
				<a href="https://bridgeapi.zendesk.com/hc/en-150/articles/4428826451602-Guide-How-to-make-your-first-test-payment-" target="_blank">
					<?php _e('Test Payments', 'bridgeapi-io');?>
				</a>
			</li>
		</ul>
		<strong><?php _e('Go to production', 'bridgeapi-io');?></strong>
		<ul style="list-style: square; margin-left: 30px;">
			<li>
				<?php printf(__('Schedule an appointment %s', 'bridgeapi-io'), '<a href="https://meetings.hubspot.com/david-l2" target="_blank">' . __('here', 'bridgeapi-io') . '</a>');?>
			</li>
		</ul>
		<strong><?php _e('Need help?', 'bridgeapi-io');?></strong>
		<ul style="list-style: square; margin-left: 30px;">
			<li>
				<?php printf(__('On the solution, the coverage? Please visit our help center %s', 'bridgeapi-io'), '<a href="https://bridgeapi.zendesk.com/hc/en-150" target="_blank">' . __('here', 'bridgeapi-io') . '</a>');?>
			</li>
			<li>
				<?php printf(__('Setting up the module? Contact our delivery team %s', 'bridgeapi-io'), '<a href="mailto:delivery@bridgeapi.io">' . __('here', 'bridgeapi-io') . '</a>');?>
			</li>
			<li>
				<?php printf(__('Technical issues in production? Contact our Care team %s', 'bridgeapi-io'), '<a href="mailto:support@bridgeapi.io">' . __('here', 'bridgeapi-io') . '</a>');?>
			</li>
		</ul>
	</div>
</section>