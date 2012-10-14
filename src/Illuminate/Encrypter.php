<?php namespace Illuminate;

class DecryptException extends \RuntimeException {}

class Encrypter {

	/**
	 * The encryption key.
	 *
	 * @var string
	 */
	protected $key;

	/**
	 * The algorithm used for encryption.
	 *
	 * @var string
	 */
	protected $cipher = 'rijndael-256';

	/**
	 * The mode used for encrpytion.
	 *
	 * @var string
	 */
	protected $mode = 'ctr';

	/**
	 * The block size of the cipher.
	 *
	 * @var int
	 */
	protected $block = 32;

	/**
	 * Create a new encrypter instance.
	 *
	 * @param  string  $key
	 * @return void
	 */
	public function __construct($key)
	{
		$this->key = $key;
	}

	/**
	 * Encrypt the given value.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public function encrypt($value)
	{
		$iv = mcrypt_create_iv($this->getIvSize());

		$value = base64_encode($this->padAndMcrypt($value, $iv));

		// Once we have the encrypted value we will go ahead base64_encode the input
		// vector and create the MAC for the encrypted value so we can verify its
		// authenticity. Then, we'll JSON encode the data in a "payload" array.
		$iv = base64_encode($iv);

		$mac = $this->hash($value);

		return base64_encode(json_encode(compact('iv', 'value', 'mac')));
	}

	/**
	 * Padd and use mcrypt on the given value and input vector.
	 *
	 * @param  string  $value
	 * @param  string  $iv
	 * @return string
	 */
	protected function padAndMcrypt($value, $iv)
	{
		$value = $this->addPadding(serialize($value));

		return mcrypt_encrypt($this->cipher, $this->key, $value, $this->mode, $iv);
	}

	/**
	 * Decrypt the given value.
	 *
	 * @param  string  $value
	 * @return string
	 */
	public function decrypt($payload)
	{
		$payload = $this->getJsonPayload($payload);

		// We'll go ahead and remove the PKCS7 padding from the encrypted value before
		// we decrypt it. Once we have the de-padded value, we will grab the vector
		// and decrypt the data, passing back the unserialized from of the value.
		$value = $this->stripPadding(base64_decode($payload['value']));

		$iv = base64_decode($payload['iv']);

		return unserialize(rtrim($this->mcryptDecrypt($value, $iv)));
	}

	/**
	 * Run the mcrypt decryption routine for the value.
	 *
	 * @param  string  $value
	 * @param  string  $iv
	 * @return string
	 */
	protected function mcryptDecrypt($value, $iv)
	{
		return mcrypt_decrypt($this->cipher, $this->key, $value, $this->mode, $iv);
	}

	/**
	 * Get the JSON array from the given payload.
	 *
	 * @param  string  $payload
	 * @return array
	 */
	protected function getJsonPayload($payload)
	{
		$payload = json_decode(base64_decode($payload), true);

		// If the payload is not valid JSON or does not have the proper keys set we will
		// assume it is invalid and bail out of the routine since we will not be able
		// to decrypt the given value. We'll also check the MAC for this encrypion.
		if ( ! $payload or $this->invalidPayload($payload))
		{
			throw new DecryptException("Invalid data passed to encrypter.");
		}

		if ($payload['mac'] != $this->hash($payload['value']))
		{
			throw new DecryptException("Message authentication code invalid.");
		}

		return $payload;
	}

	/**
	 * Create a MAC for the given value.
	 *
	 * @parma  
	 */
	protected function hash($value)
	{
		return hash_hmac('sha256', $value, $this->key);
	}

	/**
	 * Add PKCS7 padding to a given value.
	 *
	 * @param  string  $value
	 * @return string
	 */
	protected function addPadding($value)
	{
		$pad = $this->block - (strlen($value) % $this->block);

		return $value.str_repeat(chr($pad), $pad);
	}

	/**
	 * Remove the padding from the given value.
	 *
	 * @param  string  $value
	 * @return string
	 */
	protected function stripPadding($value)
	{
		$pad = ord($value[($len = strlen($value)) - 1]);

		return $this->paddingIsValid($pad, $value) ? substr($value, 0, -$pad) : $value;
	}

	/**
	 * Determine if the given padding for a value is valid.
	 *
	 * @param  string  $pad
	 * @param  string  $value
	 * @return bool
	 */
	protected function paddingIsValid($pad, $value)
	{
		return $pad and $pad < $this->block and preg_match('/'.chr($pad).'{'.$pad.'}$/', $value);
	}

	/**
	 * Verify that the encryption payload is valid.
	 *
	 * @param  array  $data
	 * @return bool
	 */
	protected function invalidPayload(array $data)
	{
		return ! isset($data['iv']) or ! isset($data['value']) or ! isset($data['mac']);
	}

	/**
	 * Get the IV size for the cipher.
	 *
	 * @return int
	 */
	protected function getIvSize()
	{
		return mcrypt_get_iv_size($this->cipher, $this->mode);
	}

}