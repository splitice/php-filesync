<?php
namespace Splitice\FileSync;

use League\Flysystem\FilesystemInterface;
use phpseclib\Net\SFTP;
use Radical\Basic\Arr\Object\CollectionObject;

class RemoteSync extends CollectionObject
{
	private $index_file;
	private $directories = array();
	private $cleanup = array();

	/**
	 * @var FilesystemInterface
	 */
	private $filesystem;
	/**
	 * @var callable
	 */
	private $activity_hook;

	/**
	 * RemoteSync constructor.
	 * @param string $index_file
	 * @param $filesystem
	 * @param null $activity_hook
	 */
	function __construct($index_file, $filesystem, $activity_hook = null)
	{
		$this->filesystem = $filesystem;
		$this->index_file = $index_file;
		parent::__construct();
		$this->activity_hook = $activity_hook;
	}

	function add_directory($dir, $data = array())
	{
		$this->directories[$dir] = $data;
	}

	function add_file($file, $data)
	{
		$this[$file] = $data;
	}

	private function on_activity(){
		$ah = $this->activity_hook;
		if(!$ah) return;
		$ah();
	}

	protected function _Set($k, $v)
	{
		if (is_array($v)) {
			$v = new FileEntry(isset($v['contents']) ? $v['contents'] : null, isset($v['hash']) ? $v['hash'] : null, isset($v['user']) ? $v['user'] : null, isset($v['perm']) ? $v['perm'] : null);
		} elseif (is_string($v) || (is_object($v) && method_exists($v, '__toString'))) {
			$v = new FileEntry($v);
		}

		if(!($v instanceof FileEntry)){
			throw new \Exception('Invalid type: '.gettype($v));
		}
		parent::_Set($k, $v);
	}


	function read_index()
	{
		if (!$this->filesystem->has($this->index_file)) {
			echo "Index doesnt exist: $this->index_file\r\n";
			return false;
		}

		try {
			$data = $this->filesystem->read($this->index_file);
		} catch (\Exception $ex) {
			throw new \Exception('An error occurred fetching: ' . $this->index_file . ' error: ' . $ex->getMessage());
		}

		return json_decode($data, true);
	}

	function write_index(FilesystemInterface $sftp, $uid_cache)
	{
		$data = array('files' => $this->build_index_file_map(), 'directories' => $this->directories, 'cleanup' => $this->cleanup, 'uid_cache'=>$uid_cache);
		/** @noinspection PhpInternalEntityUsedInspection */
		$sftp->put($this->index_file, json_encode($data));
	}

	function build_index_file_map()
	{
		$map = array();
		foreach ($this as $filename => $data) {
			/** @var FileEntry $data */
			$file = array('hash' => $data->hash());
			if ($data->getPerm()) {
				$file['perm'] = $data->getPerm();
			}
			if ($data->getUser()) {
				$file['user'] = $data->getUser();
			}
			$map[$filename] = $file;
		}
		return $map;
	}

	private function getFilesToDo($index, &$toUpload, &$toDelete, &$toPerm, &$toOwn)
	{
		$toUpload = $toDelete = array();
		if ($index == false) {
			//Full upload
			$toUpload = array_keys($this->toArray());
		} else {
			$current = $this->build_index_file_map();
			foreach ($index['files'] as $file => $data) {
				$perm = 664;
				$user = 'root';
				if (is_string($data)) {
					$hash = $data;
				} else {
					$hash = $data['hash'];
					if (isset($data['perm'])) {
						$perm = (int)$data['perm'];
					}
					if (isset($data['user'])) {
						$user = $data['user'];
					}
				}

				//Deleted files
				if (!isset($current[$file])) {
					$toDelete[] = $file;
					continue;
				}

				//Updated files
				$cf = $current[$file];
				if ($cf['hash'] != $hash) {
					$toUpload[] = $file;
				}
				if (isset($cf['perm'])) {
					if ($cf['perm'] != $perm) {
						$toPerm[$file] = $cf['perm'];
					}
				}
				if (isset($cf['user'])) {
					if ($cf['user'] != $user) {
						$toOwn[$file] = $cf['user'];
					}
				}
			}

			//New files
			foreach ($current as $file => $cf) {
				if (!isset($index['files'][$file])) {
					$toUpload[] = $file;

					if (isset($cf['perm'])) {
						$toPerm[$file] = $cf['perm'];
					}
					if (isset($cf['user'])) {
						$toOwn[$file] = $cf['user'];
					}
				}
			}
		}
	}

	private function getDirectoriesToDo($index, &$toCreate, &$toDelete, &$toOwn)
	{
		$toCreate = $toDelete = array();
		if ($index == false) {
			//Full upload
			$index = array('directories' => array());
		}

		foreach ($index['directories'] as $dir=>$data) {
			$user = 'root';
			if (isset($data['user'])) {
				$user = $data['user'];
			}

			//Deleted files
			if (!isset($this->directories[$dir])) {
				$toDelete[] = $dir;
				continue;
			}

			$cd = $this->directories[$dir];
			if (isset($cd['user'])) {
				if ($cd['user'] != $user) {
					$toOwn[$dir] = $cd['user'];
				}
			}
		}

		//New files
		foreach ($this->directories as $dir=>$cd) {
			if (!isset($index['directories'][$dir])) {
				$toCreate[] = $dir;

				if (isset($cd['user'])) {
					$toOwn[$dir] = $cd['user'];
				}
			}
		}
	}

	private function do_delete(FilesystemInterface $sftp, $toDelete)
	{
		$changes_made = false;
		foreach ($toDelete as $file) {
			echo "Deleting $file\r\n";
			if($sftp->has($file)) {
				$sftp->delete($file);
			}
			$this->on_activity();

			$changes_made = true;
		}

		return $changes_made;
	}

	private function do_own_sftp(SFTP $filesystem, $toOwn, &$uid_cache)
	{
		$changes_made = false;
		foreach ($toOwn as $file => $user) {
			if (isset($uid_cache[$user])) {
				$uid = $uid_cache[$user];
			} else {
				$uid = $filesystem->exec('id ' . escapeshellarg($user) . ' -u');
				if ($uid === false) {
					throw new \Exception('Error looking up user ID. Error: ' . $filesystem->getLastError());
				}
				$uid = (int)trim($uid);
				$uid_cache[$user] = $uid;
			}


			echo "Changing owner of $file to $user ($uid)\r\n";
			$filesystem->chown($file, $uid);
			$this->on_activity();

			$changes_made = true;
		}

		return $changes_made;
	}

	private function do_directory_create(FilesystemInterface $sftp, $toCreate)
	{
		$changes_made = false;
		foreach ($toCreate as $dir) {
			echo "Creating directory $dir\r\n";
			$sftp->createDir($dir);
			$this->on_activity();

			$changes_made = true;
		}

		return $changes_made;
	}

	private function do_upload(FilesystemInterface $sftp, $toUpload)
	{
		$changes_made = false;
		foreach ($toUpload as $name) {
			//Upload file
			echo "Writing $name\r\n";
			$data = (string)($this[$name]->contents());
			/** @noinspection PhpInternalEntityUsedInspection */
			$sftp->put($name, $data);
			$this->on_activity();

			$changes_made = true;
		}
		return $changes_made;
	}

	private function execute_cleanup_process()
	{

	}

	function sync(SFTP $sftp)
	{
		$filesystem = $this->filesystem;
		$changes_made = false;
		$filesToDelete = $filesToUpload = $directoriesToDelete = $directoriesToCreate = $toPerm = $toOwn = $uid_cache = array();

		$index = $this->read_index();
		if($index && isset($index['uid_cache'])) {
			$uid_cache = $index['uid_cache'];
		}

		$this->getFilesToDo($index, $filesToUpload, $filesToDelete, $toPerm, $toOwn);
		$this->getDirectoriesToDo($index, $directoriesToCreate, $directoriesToDelete, $toOwn);

		//Create directories
		$changes_made |= $this->do_directory_create($filesystem, $directoriesToCreate);

		//Delete files
		$changes_made |= $this->do_delete($filesystem, $filesToDelete);

		//Upload files
		$changes_made |= $this->do_upload($filesystem, $filesToUpload);

		//Chown files/directories
		$changes_made |= $this->do_own_sftp($sftp, $toOwn, $uid_cache);

		//Delete directories (only if empty)
		$changes_made |= $this->do_delete($filesystem, $directoriesToDelete);

		if ($changes_made) {
			$this->write_index($filesystem, $uid_cache);
			$this->execute_cleanup_process();
			$this->on_activity();
		}

		return array_merge($filesToDelete, $filesToUpload, $directoriesToCreate, $directoriesToDelete);
	}
} 