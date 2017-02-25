<?php
namespace Splitice\FileSync;


class FileEntry
{
	private $contents;
	private $hash;
	private $user;
	private $perm;

	function __construct($contents, $hash = null, $user = null, $perm = null)
	{
		$this->contents = $contents;
		$this->user = $user;
		$this->perm = $perm;
		$this->setHash($hash);
	}

	/**
	 * @return mixed
	 */
	public function getContents()
	{
		return $this->contents;
	}

	/**
	 * @param mixed $contents
	 */
	public function setContents($contents)
	{
		$this->contents = $contents;
	}

	/**
	 * @return mixed
	 */
	public function getHash()
	{
		return $this->hash;
	}

	/**
	 * @param mixed $hash
	 */
	public function setHash($hash)
	{
		$this->hash = $hash;
	}

	/**
	 * @return mixed
	 */
	public function getUser()
	{
		return $this->user;
	}

	/**
	 * @param mixed $user
	 */
	public function setUser($user)
	{
		$this->user = $user;
	}

	/**
	 * @return mixed
	 */
	public function getPerm()
	{
		return $this->perm;
	}

	/**
	 * @param mixed $perm
	 */
	public function setPerm($perm)
	{
		$this->perm = $perm;
	}

	function contents()
	{
		$contents = $this->contents;
		if (is_callable($contents)) {
			$this->contents = $contents();
		}

		return $this->contents;
	}

	function calculate_hash($text)
	{
		return base64_encode(md5((string)$text, true));
	}

	function hash()
	{
		if ($this->hash !== null) {
			if (is_callable($this->hash)) {
				$hash = $this->hash;
				$this->hash = $hash();
			}

			return $this->hash;
		}

		$text = $this->contents();
		$this->hash = self::calculate_hash($text);
		return $this->hash;
	}

	function compare_hash($data)
	{
		if (is_callable($data)) {
			$data = $data();
		}
		return self::calculate_hash($data) == $this->hash();
	}
}