<?php
	/**
	 * @file
	 * Contains \Drupal\gigya_user_deletion\Form\GigyaCronForm.
	 */

	namespace Drupal\gigya_user_deletion\Form;

	use Drupal\Core\Form\ConfigFormBase;
	use Drupal\Core\Form\FormStateInterface;
	use Drupal\gigya\Helper\GigyaHelper;
	use Drupal\gigya\Helper\GigyaHelperInterface;
	use Aws\S3\S3Client;
	use Aws\S3\Exception\S3Exception;

	class GigyaCronForm extends ConfigFormBase
	{
		public $helper = false;

		private function getValue($form_state, $prop_name) {
			return trim($form_state->getValue($prop_name));
		}

		/**
		 * Gets the configuration names that will be editable.
		 *
		 * @return array
		 *   An array of configuration object names that are editable if called in
		 *   conjunction with the trait's config() method.
		 */
		protected function getEditableConfigNames() {
			return [
				'gigya.job',
			];
		}

		/**
		 * @param array                                $form
		 * @param \Drupal\Core\Form\FormStateInterface $form_state
		 * @param GigyaHelperInterface                 $helper
		 * @return array
		 */
		public function buildForm(array $form, FormStateInterface $form_state, GigyaHelperInterface $helper = null) {
			if ($helper == NULL)
			{
				$this->helper = new GigyaHelper();
			}
			else
			{
				$this->helper = $helper;
			}
			if (!$this->helper->checkEncryptKey())
			{
				drupal_set_message($this->t('Cannot read encrypt key'), 'error');
			}
			$form = parent::buildForm($form, $form_state);
			$config = $this->config('gigya.job');

			$form['enableJobLabel'] = array(
				'#type' => 'label',
				'#title' => $this->t('Enable'),
				"#attributes" => array(
					'class' => 'gigya-label-cb'
				)
			);
			$form['enableJob'] = array(
				'#type' => 'checkbox',
				'#default_value' => $config->get('gigya.enableJob'),
			);

			/* Job frequency */
			$form['jobFrequency'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Job frequency (minutes)'),
//				'#required' => true,
			);
			$jobFrequency = $config->get('gigya.jobFrequency') / 60;
			if ($jobFrequency == 0)
				$form['jobFrequency']['#default_value'] = "";
			else
				$form['jobFrequency']['#default_value'] = $jobFrequency;

			/* Email on success */
			$form['emailOnSuccess'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Email on success'),
				'#description' => $this->t('A comma-separated list of emails that will be notified when the job completes successfully'),
				'#default_value' => $config->get('gigya.emailOnSuccess'),
			);

			/* Email on failure */
			$form['emailOnFailure'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Email on failure'),
				'#description' => $this->t('A comma-separated list of emails that will be notified when the job fails or completes with errors'),
				'#default_value' => $config->get('gigya.emailOnFailure'),
			);

			/* S3 Storage Details */

			/* Label */
			$form['storage'] = array(
				'#type' => 'label',
				'#title' => $this->t('Amazon S3 settings'),
				'#attributes' => array(
					'class' => 'gigya-label-custom'
				)
			);

			/* Bucket name */
			$form['storageDetails']['bucketName'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Bucket name'),
//				'#required' => true,
				'#default_value' => $config->get('gigya.storageDetails.bucketName'),
			);

			/* Access key */
			$form['storageDetails']['accessKey'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Access key'),
//				'#required' => true,
				'#default_value' => $config->get('gigya.storageDetails.accessKey'),
			);

			/* Secret key */
			$key = $config->get('gigya.storageDetails.secretKey');
			$access_key = "";
			if (!empty($key))
			{
				$access_key = $this->helper->decrypt($key);
			}
			$form['storageDetails']['secretKey'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Secret key'),
//				'#required' => true,
			);
			if (!empty($access_key))
			{
				$form['storageDetails']['secretKey']['#default_value'] = "*********";
				$form['storageDetails']['secretKey']['#description'] = $this->t(
					"Current key first letters are @accessKey", array('@accessKey' => substr($access_key, 0, 2) . "******" . substr($access_key, strlen($access_key) - 2, 2))
				);

			}

			/* Object key prefix (directory) */
			$form['storageDetails']['objectKeyPrefix'] = array(
				'#type' => 'textfield',
				'#title' => $this->t('Object key prefix'),
				'#default_value' => $config->get('gigya.storageDetails.objectKeyPrefix'),
			);
			/* .S3 Storage Details */

			return $form;
		}

		/**
		 * Returns a unique string identifying the form.
		 *
		 * @return string
		 *   The unique string identifying the form.
		 */
		public function getFormId() {
			return 'gigya_job_params';
		}

		public function validateForm(array &$form, FormStateInterface $form_state) {
			/* Check if the form has errors, if true we do not need to validate the user input because it has errors already. */
			parent::validateForm($form, $form_state);
			if ($form_state->getErrors())
				return;

			$jobRequiredFields = ['jobFrequency', 'storageDetails.bucketName', 'storageDetails.accessKey', 'storageDetails.secretKey'];

			// if $_enableJob is true verify all required fields with value
			$_enableJob = $this->getValue($form_state, 'enableJob');

			if ($_enableJob)
			{
				$helper = new GigyaHelper();
				if (!$helper->checkEncryptKey())
				{
					drupal_set_message($this->t('Cannot read encrypt key'), 'error');
				}

				foreach ($jobRequiredFields as $field)
				{
					if (strpos($field, 'storageDetails') !== false)
					{
						$num = strlen($field) - strlen('storageDetails') - 1;
						$field = substr($field, 0 - $num);
						$fieldValue = $form['storageDetails'][$field]['#value'];
					}
					else
					{
						$fieldValue = $this->getValue($form_state, $field);
					}

					if (empty($fieldValue))
					{
						$form_state->setErrorByName($field, $this->t($field . " is required field if job enabled."));
						break;
					}
				}

				$bucketName = $this->getValue($form_state, 'bucketName');
				$accessKey = $this->getValue($form_state, 'accessKey');
				$secretKey = $this->getValue($form_state, 'secretKey');

				//if secret encrypt -> decrypt it
				if (empty($secretKey) || $secretKey === "*********")
				{
					$secretKeyEnc = \Drupal::config('gigya.job')->get('gigya.storageDetails.secretKey');
					$secretKey = $helper->decrypt($secretKeyEnc);
				}

				$s3Client = S3Client::factory(array(
										 'key' => $accessKey,
										 'secret' => $secretKey,
									 ));

				try
				{
					$s3Client->GetBucketLocation(array('Bucket' => $bucketName,));
				}
				catch (S3Exception $e)
				{
					\Drupal::logger('gigya_user_deletion')->error("Failed to connect to S3 server (form) - " . $e->getMessage());
					$form_state->setErrorByName('storageDetails.secretKey', $this->t("Failed connecting to S3 server with error: " . $e->getMessage()));
				}
			}
		}

		public function submitForm(array &$form, FormStateInterface $form_state) {
			$config = $this->config('gigya.job');
			$config->set('gigya.enableJob', $this->getValue($form_state, 'enableJob'));

			$jobFrequency = $this->getValue($form_state, 'jobFrequency') * 60;
			if ($jobFrequency == 0)
			{
				$config->set('gigya.jobFrequency', "");
			}
			else
			{
				$config->set('gigya.jobFrequency', $jobFrequency);
			}
			$config->set('gigya.emailOnSuccess', $this->getValue($form_state, 'emailOnSuccess'));
			$config->set('gigya.emailOnFailure', $this->getValue($form_state, 'emailOnFailure'));
			$config->set('gigya.storageDetails.bucketName', $this->getValue($form_state, 'bucketName'));
			$config->set('gigya.storageDetails.accessKey', $this->getValue($form_state, 'accessKey'));
			//encrypt storageDetails.secret
			$temp_access_key = $this->getValue($form_state, 'secretKey');
			if (!empty($temp_access_key) && $temp_access_key !== "*********")
			{
				$enc = $this->helper->enc($temp_access_key);
				$config->set('gigya.storageDetails.secretKey', $enc);
			}
			$config->set('gigya.storageDetails.objectKeyPrefix', $this->getValue($form_state, 'objectKeyPrefix'));
			$config->save();
			parent::submitForm($form, $form_state);
		}
	}
