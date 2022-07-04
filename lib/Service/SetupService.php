<?php

declare(strict_types=1);

/**
 * @copyright 2017 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @author 2017 Christoph Wurst <christoph@winzerhof-wurst.at>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\Mail\Service;

use Horde_Imap_Client_Exception;
use Horde_Mail_Exception;
use Horde_Mail_Transport_Smtphorde;
use InvalidArgumentException;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Db\TagMapper;
use OCA\Mail\Exception\CouldNotConnectException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\IMAP\IMAPClientFactory;
use OCA\Mail\Service\AutoConfig\AutoConfig;
use OCA\Mail\SMTP\SmtpClientFactory;
use OCP\Security\ICrypto;
use Psr\Log\LoggerInterface;
use function in_array;

class SetupService {

	/** @var AutoConfig */
	private $autoConfig;

	/** @var AccountService */
	private $accountService;

	/** @var ICrypto */
	private $crypto;

	/** @var SmtpClientFactory */
	private $smtpClientFactory;

	/** @var IMAPClientFactory */
	private $imapClientFactory;

	/** @var LoggerInterface */
	private $logger;

	/** @var TagMapper */
	private $tagMapper;

	public function __construct(AutoConfig $autoConfig,
								AccountService $accountService,
								ICrypto $crypto,
								SmtpClientFactory $smtpClientFactory,
								IMAPClientFactory $imapClientFactory,
								LoggerInterface $logger,
								TagMapper $tagMapper) {
		$this->autoConfig = $autoConfig;
		$this->accountService = $accountService;
		$this->crypto = $crypto;
		$this->smtpClientFactory = $smtpClientFactory;
		$this->imapClientFactory = $imapClientFactory;
		$this->logger = $logger;
		$this->tagMapper = $tagMapper;
	}

	/**
	 * @param string $accountName
	 * @param string $emailAddress
	 * @param string $password
	 * @return Account|null
	 *
	 * @link https://github.com/nextcloud/mail/issues/25
	 */
	public function createNewAutoConfiguredAccount($accountName, $emailAddress, $password) {
		$this->logger->info('setting up auto detected account');
		$mailAccount = $this->autoConfig->createAutoDetected($emailAddress, $password, $accountName);
		if (is_null($mailAccount)) {
			return null;
		}

		$this->accountService->save($mailAccount);

		$this->tagMapper->createDefaultTags($mailAccount);

		return new Account($mailAccount);
	}

	/**
	 * @throws CouldNotConnectException
	 * @throws ServiceException
	 *
	 * @return Account
	 */
	public function createNewAccount(string $accountName,
		string $emailAddress,
		string $imapHost,
		int $imapPort,
		string $imapSslMode,
		string $imapUser,
		?string $imapPassword,
		string $smtpHost,
		int $smtpPort,
		string $smtpSslMode,
		string $smtpUser,
		?string $smtpPassword,
		string $uid,
		string $authMethod,
		?int $accountId = null): Account {
		$this->logger->info('Setting up manually configured account');
		$newAccount = new MailAccount([
			'accountId' => $accountId,
			'accountName' => $accountName,
			'emailAddress' => $emailAddress,
			'imapHost' => $imapHost,
			'imapPort' => $imapPort,
			'imapSslMode' => $imapSslMode,
			'imapUser' => $imapUser,
			'imapPassword' => $imapPassword,
			'smtpHost' => $smtpHost,
			'smtpPort' => $smtpPort,
			'smtpSslMode' => $smtpSslMode,
			'smtpUser' => $smtpUser,
			'smtpPassword' => $smtpPassword
		]);
		$newAccount->setUserId($uid);
		if ($imapPassword !== null) {
			$newAccount->setInboundPassword($this->crypto->encrypt($imapPassword));
		}
		if ($smtpPassword !== null) {
			$newAccount->setOutboundPassword($this->crypto->encrypt($smtpPassword));
		}
		if (!in_array($authMethod, ['password', 'xoauth2'], true)) {
			throw new InvalidArgumentException('Invalid auth method ' . $authMethod);
		}
		$newAccount->setAuthMethod($authMethod);

		$account = new Account($newAccount);
		if ($imapPassword !== null) {
			$this->logger->debug('Connecting to account {account}', ['account' => $newAccount->getEmail()]);
			$this->testConnectivity($account);
		}

		$this->accountService->save($newAccount);
		$this->logger->debug("account created " . $newAccount->getId());

		$this->tagMapper->createDefaultTags($newAccount);

		return $account;
	}

	/**
	 * @param Account $account
	 * @throws CouldNotConnectException
	 */
	protected function testConnectivity(Account $account): void {
		$mailAccount = $account->getMailAccount();

		$imapClient = $this->imapClientFactory->getClient($account);
		try {
			$imapClient->login();
		} catch (Horde_Imap_Client_Exception $e) {
			throw new CouldNotConnectException($e, 'IMAP', $mailAccount->getInboundHost(), $mailAccount->getInboundPort());
		} finally {
			$imapClient->logout();
		}

		$transport = $this->smtpClientFactory->create($account);
		if ($transport instanceof Horde_Mail_Transport_Smtphorde) {
			try {
				$transport->getSMTPObject();
			} catch (Horde_Mail_Exception $e) {
				throw new CouldNotConnectException($e, 'SMTP', $mailAccount->getOutboundHost(), $mailAccount->getOutboundPort());
			}
		}
	}
}
