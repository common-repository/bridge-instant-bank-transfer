var bridgeSuccessIcon = jQuery(`<span class="dashicons dashicons-yes" style="font-size: xx-large; color: #00a32a;"></span>`)
var bridgeFailureIcon = jQuery(`<span class="dashicons dashicons-no" style="font-size: xx-large; color: #d63638;"></span>`)
var bridgeLoadingImage = jQuery(`<img src="${BRIDGE_SETTINGS.spinner}" style="margin-top: 5px;" />`)

jQuery(document).ready($ => {
	$('#woocommerce_bridgeapi-io_check_btn').click(e => bridgeCheckCredentials('prod', e))
	$('#woocommerce_bridgeapi-io_check_test_btn').click(e => bridgeCheckCredentials('test', e))
	$('#woocommerce_bridgeapi-io_check_sandbox_webhook').click(bridgeTestWebhook.bind(this, 1, true))
	$('#woocommerce_bridgeapi-io_webook_btn').click(bridgeTestWebhook.bind(this, 0, true))
	$('#woocommerce_bridgeapi-io_test_app_secret, #woocommerce_bridgeapi-io_app_secret, #woocommerce_bridgeapi-io_test_webhook_secret, #woocommerce_bridgeapi-io_webhook_secret').focus(e => {
		$(e.target).prop('type', 'text')
	})
	.blur(e => {
		$(e.target).prop('type', 'password')
	})

	bridgeTestWebhook(1, false, $('#woocommerce_bridgeapi-io_check_sandbox_webhook'))
	bridgeTestWebhook(0, false, $('#woocommerce_bridgeapi-io_webook_btn'))
})

const bridgeBtnBeforeRequest = ($el) => {
	$el.prop('disabled', true)
	$el.next('span.dashicons').remove()
	$el.after(bridgeLoadingImage.clone())
}

const bridgeBtnAfterRequest = ($el, $icon, isTest) => {
	$el.prop('disabled', false)
	$el.next('img').remove()
	$el.after($icon)

	if (isTest) {
		setTimeout(() => {
			$icon.remove()
		},5000)
	}
}

const bridgeTestWebhook = (isSandbox, isTest, e) => {
	if (e.target)
		e.preventDefault()

	var $this = jQuery(e.target ? e.target : e)
	let form = new FormData()
	form.append('action', 'bridge-io/ajax/webhook/test');
	form.append('__nonce', BRIDGE_SETTINGS.nonce)
	form.append('isSandbox', isSandbox)

	bridgeBtnBeforeRequest($this)

	fetch(BRIDGE_SETTINGS.ajax, {
		credentials: 'same-origin',
		method: 'POST',
		body: form,
	})
	.then(response => response.json())
	.then(json => {
		if (json.success) {
			let icon = bridgeSuccessIcon.clone()
			bridgeBtnAfterRequest($this, icon, isTest)
			if (!isTest) {
				let date = new Date(0);
				date.setUTCSeconds(json.data)
				icon.after(jQuery('<span style="margin-top: 7px; margin-left:15px; display: inline-block;"></span>').text(`${BRIDGE_SETTINGS.text.webhook_configured}${date.toLocaleDateString()} ${date.toLocaleTimeString()}`))
			}
			if (isTest)
				setTimeout(() => {window.location.reload()}, 3000)
		} else {
			bridgeBtnAfterRequest($this, bridgeFailureIcon.clone(), isTest)
			console.log(json)
		}
	})
	.catch(e => {
		bridgeBtnAfterRequest($this, bridgeFailureIcon, isTest)
		console.log(e)
	})
}

const bridgeCheckCredentials = (type, e) => {
	e.preventDefault()
	var $this = jQuery(e.target)
	let id, secret

	if (type === 'test') {
		id = document.getElementById('woocommerce_bridgeapi-io_test_app_id').value
		secret = document.getElementById('woocommerce_bridgeapi-io_test_app_secret').value
	} else if(type === 'prod') {
		id = document.getElementById('woocommerce_bridgeapi-io_app_id').value
		secret = document.getElementById('woocommerce_bridgeapi-io_app_secret').value
	}

	if (id.trim !== '' && secret.trim() !== '') {
		bridgeBtnBeforeRequest($this)


		let form = new FormData()
		form.append('action', 'bridge-io/ajax/credentials/test');
		form.append('__nonce', BRIDGE_SETTINGS.nonce)
		form.append('app_id', id)
		form.append('app_secret', secret)
		var el;

		fetch(BRIDGE_SETTINGS.ajax, {
			body: form,
			method: 'POST',
			credentials: 'same-origin'
		})
		.then(response => response.json())
		.then(json => {
			if (json.success) {
				el = bridgeSuccessIcon
			} else {
				el = bridgeFailureIcon
				alert(json.data)
				console.log(json)
			}
		})
		.catch(error => {
			el = bridgeSuccessIcon
			console.log(error)
		})
		.finally( () => {
			bridgeBtnAfterRequest($this, el, false)
		})
	} else {
		alert(BRIDGE_SETTINGS.text.provide_credentials);
	}
}