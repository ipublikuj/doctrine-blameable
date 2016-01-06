<?php
/**
 * Test: IPub\DoctrineBlameable\Blameable
 * @testCase
 *
 * @copyright      More in license.md
 * @license        http://www.ipublikuj.eu
 * @author         Adam Kadlec http://www.ipublikuj.eu
 * @package        iPublikuj:DoctrineBlameable!
 * @subpackage     Tests
 * @since          1.0.0
 *
 * @date           06.01.16
 */

namespace IPubTests\DoctrineBlameable;

use Nette;

use Tester;
use Tester\Assert;

use Doctrine;
use Doctrine\ORM;
use Doctrine\Common;

use IPub;
use IPub\DoctrineBlameable;
use IPub\DoctrineBlameable\Events;
use IPub\DoctrineBlameable\Mapping;

require __DIR__ . '/../bootstrap.php';
require_once __DIR__ . '/models/ArticleEntity.php';
require_once __DIR__ . '/models/ArticleMultiChangeEntity.php';
require_once __DIR__ . '/models/UserEntity.php';
require_once __DIR__ . '/models/TypeEntity.php';

/**
 * Registering doctrine blameable functions tests
 *
 * @package        iPublikuj:DoctrineBlameable!
 * @subpackage     Tests
 *
 * @author         Adam Kadlec <adam.kadlec@fastybird.com>
 */
class BlameableTest extends Tester\TestCase
{
	/**
	 * @var \Nette\DI\Container
	 */
	private $container;

	/**
	 * @var \Kdyby\Doctrine\EntityManager
	 */
	private $em;

	/**
	 * @var Events\BlameableListener
	 */
	private $listener;

	/**
	 * @var DoctrineBlameable\Configuration
	 */
	private $configuration;

	protected function setUp()
	{
		parent::setUp();

		$this->container = $this->createContainer();
		$this->em = $this->container->getByType('Kdyby\Doctrine\EntityManager');
		$this->listener = $this->container->getByType('IPub\DoctrineBlameable\Events\BlameableListener');
		$this->configuration = $this->container->getByType('IPub\DoctrineBlameable\Configuration');
	}

	public function testCreate()
	{
		$this->generateDbSchema();

		$this->listener->setUser('tester');

		$article = new Models\ArticleEntity;

		$this->em->persist($article);
		$this->em->flush();

		Assert::equal('tester', $article->getCreatedBy());
		Assert::equal('tester', $article->getUpdatedBy());
	}

	public function testUpdate()
	{
		$this->generateDbSchema();

		$this->listener->setUser('tester');

		$article = new Models\ArticleEntity;

		$this->em->persist($article);
		$this->em->flush();

		$id = $article->getId();
		$createdBy = $article->getCreatedBy();

		$this->em->clear();

		$this->listener->setUser('secondUser');

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);
		$article->setTitle('test'); // Need to modify at least one column to trigger onUpdate

		$this->em->flush();

		Assert::equal($createdBy, $article->getCreatedBy());
		Assert::equal('secondUser', $article->getUpdatedBy());
		Assert::notEqual($article->getCreatedBy(), $article->getUpdatedBy());

		$this->listener->setUser('publisher');

		$published = new Models\TypeEntity;
		$published->setTitle('Published');

		$article->setType($published);

		$this->em->persist($article);
		$this->em->persist($published);
		$this->em->flush();
		$this->em->clear();

		$id = $article->getId();

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);

		Assert::equal('publisher', $article->getPublishedBy());
	}

	public function testRemove()
	{
		$this->generateDbSchema();

		$article = new Models\ArticleEntity;

		$this->em->persist($article);
		$this->em->flush();

		$id = $article->getId();

		$this->em->clear();

		$this->listener->setUser('secondUser');

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);

		$this->em->remove($article);
		$this->em->flush();
		$this->em->clear();

		Assert::equal('secondUser', $article->getDeletedBy());
	}

	public function testWithUserCallback()
	{
		// Define entity name
		$this->configuration->userEntity = 'IPubTests\DoctrineBlameable\Models\UserEntity';

		$this->generateDbSchema();

		$creator = new Models\UserEntity;
		$creator->setUsername('user');

		$tester = new Models\UserEntity;
		$tester->setUsername('tester');

		$userCallback = function() use($creator) {
			return $creator;
		};

		$this->listener->setUserCallable($userCallback);

		$this->em->persist($creator);
		$this->em->persist($tester);

		$this->em->flush();

		$article = new Models\ArticleEntity;

		$this->em->persist($article);

		$this->em->flush();

		$id = $article->getId();

		// Switch user for update
		$this->listener->setUser($tester);

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);
		$article->setTitle('New article title'); // Need to modify at least one column to trigger onUpdate

		$this->em->flush();

		Assert::true($article->getCreatedBy() instanceof Models\UserEntity);
		Assert::equal($creator->getUsername(), $article->getCreatedBy()->getUsername());
		Assert::equal($tester->getUsername(), $article->getUpdatedBy()->getUsername());
		Assert::null($article->getPublishedBy());
		Assert::notEqual($article->getCreatedBy(), $article->getUpdatedBy());

		$published = new Models\TypeEntity;
		$published->setTitle('Published');

		$article->setType($published);

		$this->em->persist($article);
		$this->em->persist($published);
		$this->em->flush();
		$this->em->clear();

		$id = $article->getId();

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);

		Assert::equal($tester->getUsername(), $article->getPublishedBy()->getUsername());
	}

	/**
	 * @throws \IPub\DoctrineBlameable\Exceptions\InvalidArgumentException
	 */
	public function testPersistOnlyWithEntity()
	{
		// Define entity name
		$this->configuration->userEntity = 'IPubTests\DoctrineBlameable\Models\UserEntity';

		$this->generateDbSchema();

		$user = new Models\UserEntity;
		$user->setUsername('user');

		$userCallback = function() use($user) {
			return $user;
		};

		$this->listener->setUserCallable($userCallback);
		// Override user
		$this->listener->setUser('anonymous');

		$this->em->persist($user);
		$this->em->flush();

		$article = new Models\ArticleEntity;

		$this->em->persist($article);
		$this->em->flush();
	}

	/**
	 * @throws \IPub\DoctrineBlameable\Exceptions\InvalidArgumentException
	 */
	public function testPersistOnlyWithString()
	{
		$this->generateDbSchema();

		$user = new Models\UserEntity;
		$user->setUsername('user');

		$this->em->persist($user);
		$this->em->flush();

		// Override user
		$this->listener->setUser($user);

		$article = new Models\ArticleEntity;

		$this->em->persist($article);
		$this->em->flush();
	}

	public function testForcedValues()
	{
		$this->generateDbSchema();

		$this->listener->setUser('tester');

		$article = new Models\ArticleEntity;
		$article->setTitle('Article forced');
		$article->setCreatedBy('forcedUser');
		$article->setUpdatedBy('forcedUser');

		$this->em->persist($article);
		$this->em->flush();
		$this->em->clear();

		$id = $article->getId();

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);

		Assert::equal('forcedUser', $article->getCreatedBy());
		Assert::equal('forcedUser', $article->getUpdatedBy());
		Assert::null($article->getPublishedBy());

		$published = new Models\TypeEntity;
		$published->setTitle('Published');

		$article->setType($published);
		$article->setPublishedBy('forcedUser');

		$this->em->persist($article);
		$this->em->persist($published);
		$this->em->flush();
		$this->em->clear();

		$id = $article->getId();

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);

		Assert::equal('forcedUser', $article->getPublishedBy());
	}

	public function testMultipleValueTrackingField()
	{
		$this->generateDbSchema();

		$this->listener->setUser('author');

		$article = new Models\ArticleEntity;

		$this->em->persist($article);
		$this->em->flush();

		$id = $article->getId();

		$article = $this->em->getRepository('IPubTests\DoctrineBlameable\Models\ArticleEntity')->find($id);

		Assert::equal('author', $article->getCreatedBy());
		Assert::equal('author', $article->getUpdatedBy());
		Assert::null($article->getPublishedBy());

		$draft = new Models\TypeEntity;
		$draft->setTitle('Draft');

		$article->setType($draft);

		$this->em->persist($article);
		$this->em->persist($draft);
		$this->em->flush();

		Assert::null($article->getPublishedBy());

		$this->listener->setUser('editor');

		$published = new Models\TypeEntity;
		$published->setTitle('Published');

		$article->setType($published);

		$this->em->persist($article);
		$this->em->persist($published);
		$this->em->flush();

		Assert::equal('editor', $article->getPublishedBy());

		$article->setType($draft);

		$this->em->persist($article);
		$this->em->flush();

		Assert::equal('editor', $article->getPublishedBy());

		$this->listener->setUser('remover');

		$deleted = new Models\TypeEntity;
		$deleted->setTitle('Deleted');

		$article->setType($deleted);

		$this->em->persist($article);
		$this->em->persist($deleted);
		$this->em->flush();

		Assert::equal('remover', $article->getPublishedBy());
	}

	private function generateDbSchema()
	{
		$schema = new ORM\Tools\SchemaTool($this->em);
		$schema->createSchema($this->em->getMetadataFactory()->getAllMetadata());
	}

	/**
	 * @return Nette\DI\Container
	 */
	protected function createContainer()
	{
		$rootDir = __DIR__ . '/../../';

		$config = new Nette\Configurator();
		$config->setTempDirectory(TEMP_DIR);

		$config->addParameters(['container' => ['class' => 'SystemContainer_' . md5('withModel')]]);
		$config->addParameters(['appDir' => $rootDir, 'wwwDir' => $rootDir]);

		$config->addConfig(__DIR__ . '/files/config.neon', !isset($config->defaultExtensions['nette']) ? 'v23' : 'v22');
		$config->addConfig(__DIR__ . '/files/entities.neon', $config::NONE);

		DoctrineBlameable\DI\DoctrineBlameableExtension::register($config);

		return $config->createContainer();
	}
}

\run(new BlameableTest());
