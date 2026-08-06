<?php

declare(strict_types=1);

// SPDX-FileCopyrightText: 2026 Fatih AKTAS <akfatih2@gmail.com>
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace OCA\Kanso\Command;

use OCA\Kanso\Service\CardService;
use OCP\AppFramework\Db\DoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `occ kanso:rebalance` - recovery for a stack whose fractional sort keys have
 * grown past {@see \OCA\Kanso\Service\SortKeyService::MAX_KEY_LENGTH} (the move
 * endpoint reports this as 409 `rebalance_required`). It rewrites the target
 * stack's card sort keys to short, evenly-spaced values with the display order
 * preserved, restoring room for future inserts.
 *
 * Usage:
 *   occ kanso:rebalance <stackId>         rebalance one stack
 *   occ kanso:rebalance --board <boardId> rebalance every stack on a board
 */
class Rebalance extends Command {
	public function __construct(
		private CardService $cardService,
	) {
		parent::__construct();
	}

	#[\Override]
	protected function configure(): void {
		$this
			->setName('kanso:rebalance')
			->setDescription('Rewrite card sort keys to short, evenly-spaced values (recovers from sort-key overflow)')
			->addArgument(
				'stackId',
				InputArgument::OPTIONAL,
				'ID of the stack to rebalance',
			)
			->addOption(
				'board',
				null,
				InputOption::VALUE_REQUIRED,
				'Rebalance every stack on this board ID instead of a single stack',
			);
	}

	#[\Override]
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$stackArg = $input->getArgument('stackId');
		$boardArg = $input->getOption('board');

		if ($boardArg !== null && $stackArg !== null) {
			$output->writeln('<error>Pass either a stackId argument or --board, not both.</error>');
			return 1;
		}
		if ($boardArg === null && $stackArg === null) {
			$output->writeln('<error>Pass a stackId argument or --board <boardId>.</error>');
			return 1;
		}

		if ($boardArg !== null) {
			$boardId = (int)$boardArg;
			try {
				return $this->rebalanceBoard($boardId, $output);
			} catch (DoesNotExistException) {
				$output->writeln(sprintf('<error>Board %d does not exist.</error>', $boardId));
				return 1;
			}
		}

		$stackId = (int)$stackArg;
		try {
			return $this->rebalanceStack($stackId, $output);
		} catch (DoesNotExistException) {
			$output->writeln(sprintf('<error>Stack %d does not exist.</error>', $stackId));
			return 1;
		}
	}

	private function rebalanceStack(int $stackId, OutputInterface $output): int {
		$count = $this->cardService->rebalanceStack($stackId);
		$output->writeln(sprintf('Rebalanced stack %d: %d card(s) rewritten.', $stackId, $count));
		return 0;
	}

	private function rebalanceBoard(int $boardId, OutputInterface $output): int {
		$results = $this->cardService->rebalanceBoard($boardId);
		$total = array_sum($results);
		foreach ($results as $stackId => $count) {
			$output->writeln(sprintf('  stack %d: %d card(s) rewritten.', $stackId, $count));
		}
		$output->writeln(sprintf('Rebalanced board %d: %d stack(s), %d card(s) total.', $boardId, count($results), $total));
		return 0;
	}
}
