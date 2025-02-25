<?php

declare(strict_types=1);

namespace App\FrontModule\Presenters;

use App\Services\HashService;
use App\Model\MessagesRepository;
use App\Services\EncryptionService;
use App\Services\ExpiryService;
use DateTime;
use ImageStorage;
use Nette\Application\UI\Form;
use Nette\Utils\Image;
use Nette\Utils\Strings;

final class DefaultPresenter extends BasePresenter
{
	/** @var MessagesRepository */
	private $messagesRepository;
	private $encryptionService;
	private $expiryService;

	/** @var ImageStorage */
	private $imageStorage;

	public const PASSWORD_MIN_LENGTH = 3;

	public function __construct(
		MessagesRepository $messagesRepository,
		EncryptionService $encryptionService,
		ExpiryService $expiryService,
		ImageStorage $imageStorage
	)
	{
		$this->messagesRepository = $messagesRepository;
		$this->encryptionService = $encryptionService;
		$this->expiryService = $expiryService;
		$this->imageStorage = $imageStorage;
	}

	public function renderDefault()
	{

	}

	public function renderCreated(string $hash = '', bool $isImage = false)
	{
		if ($hash === '') {
			$this->redirect('default');
		}
		$this->template->hash = $hash;
		if ($isImage) {
			$this->template->messageUrl = $this->link('//Default:readImage', $hash);
		} else {
			$this->template->messageUrl = $this->link('//Default:read', $hash);
		}
	}

	public function renderRead(string $hash = '')
	{
		if ($hash === '') {
			$this->redirect('default');
		}
		$message = $this->messagesRepository->getMessage($hash);
		if (!$message) {
			$this->template->noMessage = true;
		} else {
			if ($this->isAjax()) {
				$session = $this->session->getSection('readMessage');
				if ($session['showAndDelete'] === true) {
					if ($session['withPassword'] === true) {
						$this->template->message = (object) [
							'password' => $message->password,
							'content' => $this->encryptionService->decryptWithPassword($message->content, $session['password']),
						];
					} else {
						$this->template->message = (object) [
							'password' => $message->password,
							'content' => $this->encryptionService->decrypt($message->content),
						];
					}
					$this->messagesRepository->messageRead($hash);
					unset($session['showAndDelete']);
					unset($session['withPassword']);
					unset($session['password']);
				} else {
					if ($session['wrongPassword'] === true) {
						$this->template->message = (object) [
							'password' => $message->password,
							'content' => '',
						];
						$this->template->msgError = 'Wrong password!';
						unset($session['wrongPassword']);
					} else {
						$this->template->message = (object) [
							'password' => $message->password,
							'content' => '',
						];
						$this->template->msgError = 'Something went wrong';
					}
				}
			} else {
				$this->template->message = (object) [
					'password' => $message->password,
					'content' => '',
				];
			}
		}
	}

	public function renderReadImage(string $hash = '')
	{
		if ($hash === '') {
			$this->redirect('default');
		}
		$this->template->hash = $hash;
		$message = $this->messagesRepository->getImage($hash);
		if (!$message) {
			$this->template->noMessage = true;
		} else {
			if ($this->isAjax()) {
				$session = $this->session->getSection('readMessage');
				if ($session['showAndDelete'] === true) {
					$imagePath = __DIR__ . '/../../../../www/upload/messages/decrypted/' . $message->filename;
					$encryptedPath = __DIR__ . '/../../../../www/upload/messages/encrypted/' . $message->filename;
					$note = $message->note;
					if ($session['withPassword'] === true) {
						$this->encryptionService->decryptFileWithPassword($encryptedPath, $imagePath, $session['password']);
						if ($message->note !== null) {
							$note = $this->encryptionService->decryptWithPassword($message->note, $session['password']);
						}
					} else {
						$this->encryptionService->decryptFile($encryptedPath, $imagePath);
						if ($message->note !== null) {
							$note = $this->encryptionService->decrypt($message->note);
						}
					}
					$this->template->message = (object) [
						'password' => $message->password,
						'content' => $message->filename,
						'fullPath' => $imagePath,
						'note' => $note !== null ? $note : '',
					];
					$this->messagesRepository->imageRead($hash);
					unset($session['showAndDelete']);
					unset($session['withPassword']);
					unset($session['password']);
				} else {
					if ($session['wrongPassword'] === true) {
						$this->template->message = (object) [
							'password' => $message->password,
							'content' => '',
						];
						$this->template->msgError = 'Wrong password!';
						unset($session['wrongPassword']);
					} else {
						$this->template->message = (object) [
							'password' => $message->password,
							'content' => '',
						];
						$this->template->msgError = 'Something went wrong';
					}
				}
			} else {
				$this->template->message = (object) [
					'password' => $message->password,
					'content' => '',
				];
			}
		}
	}

	public function actionShowImage(string $path)
	{
		$image = Image::fromFile($path);
		$image->send();
		unlink($path);
	}

	// public function handleUnlockMessage()
	// {
	// 	$session = $this->session->getSection('readMessage');
	// 	// if ()
	// 	$session['showAndDelete'] = true;
	// 	$this->redrawControl('message');
	// }

	public function handleShowMessage()
	{
		$session = $this->session->getSection('readMessage');
		$session['showAndDelete'] = true;
		$this->redrawControl('message');
	}

	public function actionExpireMessages(?string $hash = null, ?string $confirm = null)
	{
		if ($hash === null || $confirm === null) {
			$this->sendJson(false);
		}
		$correct = $this->expiryService->verifyExpiration($hash, $confirm);
		if ($correct) {
			$this->messagesRepository->deleteExpiredMessages();
			$this->sendJson(true);
		}
		$this->sendJson(false);
	}

	public function createComponentMessageForm(): Form
	{
		$form = new Form;

		$form->addTextArea('content')
			->setRequired()
			->setHtmlAttribute('placeholder', 'Write your message here...');
			// ->setHtmlAttribute('data-text-editor', 'message')
			// ->setHtmlAttribute('class', 'js-wysiwyg');

        // $form->addText('captcha', 'Enter captcha:');

        $form->addPassword('password', 'Password (optional):')
            ->setHtmlAttribute('placeholder', 'Enter password')
            ->addCondition(Form::FILLED, true)
            ->addRule(Form::MIN_LENGTH, "Password must be at least " . self::PASSWORD_MIN_LENGTH . " characters long.", self::PASSWORD_MIN_LENGTH);

		$form->addSubmit('save', 'Create message');

		// $form->addHidden('recaptcha_token');

		$form->onSuccess[] = [$this, 'messageFormSucceeded'];
		return $form;
	}

	public function createComponentImageForm(): Form
	{
		$form = new Form;

		$form->addUpload('image', 'Image to send')
			->setRequired()
			->addRule($form::IMAGE, 'Image needs to be a JPEG, PNG, GIF, or WebP file.');

		$form->addTextArea('note')
			->setHtmlAttribute('maxlength', '255')
			->setHtmlAttribute('data-remaining-chars', '255')
			->setHtmlAttribute('placeholder', '(optional) Write a short message (max. 255 characters)...');

		$form->addPassword('password', 'Password (optional):')
            ->setHtmlAttribute('placeholder', 'Enter password')
            ->addCondition(Form::FILLED, true)
            ->addRule(Form::MIN_LENGTH, "Password must be at least {self::PASSWORD_MIN_LENGTH} characters long.", self::PASSWORD_MIN_LENGTH);

		$form->addSubmit('save', 'Send image');

		$form->onSuccess[] = [$this, 'imageFormSucceeded'];
		return $form;
	}

	public function createComponentUnlockForm(): Form
	{
		$form = new Form;

		$form->setHtmlAttribute('data-ajax-parent', 'original_post');

		$form->addPassword('password', 'Password:')
            ->setHtmlAttribute('placeholder', 'Enter password')
			->setRequired();

		$form->addSubmit('send', 'Unlock message');

		$form->onSuccess[] = [$this, 'unlockFormSucceeded'];
		return $form;
	}

	public function messageFormSucceeded(Form $form, $values)
	{
		$data = [];
		$data['password'] = Strings::trim($values->password);
		$data['created_at'] = new DateTime();
		$data['expires_at'] = new DateTime('now + 2 DAYS');
		$url = '';
		if (strlen($data['password']) >= 3) {
			$hashedPassword = HashService::hashPassword($data['password']);
			$data['content'] = $this->encryptionService->encryptWithPassword($values->content, $data['password']);
			$data['password'] = $hashedPassword;
			$row = $this->messagesRepository->findAll()->insert($data);
			$hash = HashService::idToHash($row->id);
			$row->update(['hash' => $hash]);
		} else {
			unset($data['password']);
			// $data['secret_key'] = $this->encryptionService->key;
			$data['content'] = $this->encryptionService->encrypt($values->content);
			$row = $this->messagesRepository->findAll()->insert($data);
			$hash = HashService::idToHash($row->id);
			$row->update(['hash' => $hash]);
		}
		$url = $hash;
		$this->flashMessage('Message created!', 'success');
		$this->redirect('Default:created', $url);
	}

	public function imageFormSucceeded(Form $form, $values)
	{
		$data = [];
		$data['password'] = Strings::trim($values->password);
		$data['created_at'] = new DateTime();
		$data['expires_at'] = new DateTime('now + 2 DAYS');
		$noteTrimmed = Strings::substring($values->note, 0, 255);
		$url = '';

		if (strlen($data['password']) >= 3) {
			$hashedPassword = HashService::hashPassword($data['password']);
			if (isset($values->image) && $values->image->isOk()) {
				$savedFile = $this->imageStorage->saveImage($values->image, $this->messagesRepository->uploadDir);
				$data['filename'] = $savedFile->filename;
				$this->encryptionService->encryptFileWithPassword($savedFile->path, $savedFile->encryptPath, $data['password']);
			}
			if (Strings::length($noteTrimmed) > 0) {
				$data['note'] = $this->encryptionService->encryptWithPassword($noteTrimmed, $data['password']);
			}
			$data['password'] = $hashedPassword;
			$row = $this->messagesRepository->findAllImages()->insert($data);

			$hash = HashService::idToHash($row->id, 'images');
			$row->update(['hash' => $hash]);
		} else {
			unset($data['password']);
			if (isset($values->image) && $values->image->isOk()) {
				$savedFile = $this->imageStorage->saveImage($values->image, $this->messagesRepository->uploadDir);
				$data['filename'] = $savedFile->filename;
				$this->encryptionService->encryptFile($savedFile->path, $savedFile->encryptPath);
			}
			if (Strings::length($noteTrimmed) > 0) {
				$data['note'] = $this->encryptionService->encrypt($noteTrimmed);
			}
			$row = $this->messagesRepository->findAllImages()->insert($data);

			$hash = HashService::idToHash($row->id, 'images');
			$row->update(['hash' => $hash]);
		}

		$url = $hash;
		$this->flashMessage('Message created!', 'success');
		$this->redirect('Default:created', $url, true);
	}

	public function unlockFormSucceeded(Form $form, $values)
	{
		if (Strings::contains($this->getURL()->path, 'image')) {
			$message = $this->messagesRepository->getImage($this->getParameter('hash'));
		} else {
			$message = $this->messagesRepository->getMessage($this->getParameter('hash'));
		}
		$session = $this->session->getSection('readMessage');
		if (HashService::verifyPassword($values->password, $message->password)) {
			$session['showAndDelete'] = true;
			$session['withPassword'] = true;
			$session['password'] = $values->password;
		} else {
			$session['wrongPassword'] = true;
		}
		$this->redrawControl('message');
	}
}
