<?php

/*
 * Generated from MySQLParser.g4 by ANTLR 4.13.2
 */

namespace WPDSQL\MySQL\Generated;


/*
 * Copyright (c) 2020, 2026, Oracle and/or its affiliates.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License, version 2.0,
 * as published by the Free Software Foundation.
 *
 * This program is designed to work with certain software (including
 * but not limited to OpenSSL) that is licensed under separate terms, as
 * designated in a particular file or component or in included license
 * documentation. The authors of MySQL hereby grant you an additional
 * permission to link the program and your derivative works with the
 * separately licensed software that they have either included with
 * the program or referenced in the documentation.
 *
 * This program is distributed in the hope that it will be useful, but
 * WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See
 * the GNU General Public License, version 2.0, for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin St, Fifth Floor, Boston, MA 02110-1301 USA
 */
use WPDSQL\MySQL\MySQLBaseLexer;
use WPDSQL\MySQL\MySQLBaseRecognizer;
use WPDSQL\MySQL\SqlMode;

use Antlr\Antlr4\Runtime\Tree\ParseTreeVisitor;

/**
 * This interface defines a complete generic visitor for a parse tree produced by {@see MySQLParser}.
 */
interface MySQLParserVisitor extends ParseTreeVisitor
{
	/**
	 * Visit a parse tree produced by {@see MySQLParser::query()}.
	 *
	 * @param Context\QueryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQuery(Context\QueryContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::simpleStatement()}.
	 *
	 * @param Context\SimpleStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleStatement(Context\SimpleStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterStatement()}.
	 *
	 * @param Context\AlterStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterStatement(Context\AlterStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterDatabase()}.
	 *
	 * @param Context\AlterDatabaseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterDatabase(Context\AlterDatabaseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterDatabaseOption()}.
	 *
	 * @param Context\AlterDatabaseOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterDatabaseOption(Context\AlterDatabaseOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterEvent()}.
	 *
	 * @param Context\AlterEventContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterEvent(Context\AlterEventContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterLogfileGroup()}.
	 *
	 * @param Context\AlterLogfileGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterLogfileGroup(Context\AlterLogfileGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterLogfileGroupOptions()}.
	 *
	 * @param Context\AlterLogfileGroupOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterLogfileGroupOptions(Context\AlterLogfileGroupOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterLogfileGroupOption()}.
	 *
	 * @param Context\AlterLogfileGroupOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterLogfileGroupOption(Context\AlterLogfileGroupOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterServer()}.
	 *
	 * @param Context\AlterServerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterServer(Context\AlterServerContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterTable()}.
	 *
	 * @param Context\AlterTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterTable(Context\AlterTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterTableActions()}.
	 *
	 * @param Context\AlterTableActionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterTableActions(Context\AlterTableActionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterCommandList()}.
	 *
	 * @param Context\AlterCommandListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterCommandList(Context\AlterCommandListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterCommandsModifierList()}.
	 *
	 * @param Context\AlterCommandsModifierListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterCommandsModifierList(Context\AlterCommandsModifierListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::standaloneAlterCommands()}.
	 *
	 * @param Context\StandaloneAlterCommandsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStandaloneAlterCommands(Context\StandaloneAlterCommandsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterPartition()}.
	 *
	 * @param Context\AlterPartitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterPartition(Context\AlterPartitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterList()}.
	 *
	 * @param Context\AlterListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterList(Context\AlterListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterCommandsModifier()}.
	 *
	 * @param Context\AlterCommandsModifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterCommandsModifier(Context\AlterCommandsModifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterListItem()}.
	 *
	 * @param Context\AlterListItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterListItem(Context\AlterListItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::place()}.
	 *
	 * @param Context\PlaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPlace(Context\PlaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::restrict()}.
	 *
	 * @param Context\RestrictContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRestrict(Context\RestrictContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterOrderList()}.
	 *
	 * @param Context\AlterOrderListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterOrderList(Context\AlterOrderListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterAlgorithmOption()}.
	 *
	 * @param Context\AlterAlgorithmOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterAlgorithmOption(Context\AlterAlgorithmOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterLockOption()}.
	 *
	 * @param Context\AlterLockOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterLockOption(Context\AlterLockOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexLockAndAlgorithm()}.
	 *
	 * @param Context\IndexLockAndAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexLockAndAlgorithm(Context\IndexLockAndAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::withValidation()}.
	 *
	 * @param Context\WithValidationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWithValidation(Context\WithValidationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::validationOnly()}.
	 *
	 * @param Context\ValidationOnlyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitValidationOnly(Context\ValidationOnlyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::validationRowLimit()}.
	 *
	 * @param Context\ValidationRowLimitContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitValidationRowLimit(Context\ValidationRowLimitContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::guided()}.
	 *
	 * @param Context\GuidedContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGuided(Context\GuidedContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::removePartitioning()}.
	 *
	 * @param Context\RemovePartitioningContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRemovePartitioning(Context\RemovePartitioningContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::allOrPartitionNameList()}.
	 *
	 * @param Context\AllOrPartitionNameListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAllOrPartitionNameList(Context\AllOrPartitionNameListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterTablespace()}.
	 *
	 * @param Context\AlterTablespaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterTablespace(Context\AlterTablespaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterUndoTablespace()}.
	 *
	 * @param Context\AlterUndoTablespaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterUndoTablespace(Context\AlterUndoTablespaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::undoTableSpaceOptions()}.
	 *
	 * @param Context\UndoTableSpaceOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUndoTableSpaceOptions(Context\UndoTableSpaceOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::undoTableSpaceOption()}.
	 *
	 * @param Context\UndoTableSpaceOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUndoTableSpaceOption(Context\UndoTableSpaceOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterTablespaceOptions()}.
	 *
	 * @param Context\AlterTablespaceOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterTablespaceOptions(Context\AlterTablespaceOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterTablespaceOption()}.
	 *
	 * @param Context\AlterTablespaceOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterTablespaceOption(Context\AlterTablespaceOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeTablespaceOption()}.
	 *
	 * @param Context\ChangeTablespaceOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeTablespaceOption(Context\ChangeTablespaceOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterView()}.
	 *
	 * @param Context\AlterViewContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterView(Context\AlterViewContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewTail()}.
	 *
	 * @param Context\ViewTailContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewTail(Context\ViewTailContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewQueryBlock()}.
	 *
	 * @param Context\ViewQueryBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewQueryBlock(Context\ViewQueryBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewCheckOption()}.
	 *
	 * @param Context\ViewCheckOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewCheckOption(Context\ViewCheckOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterInstanceStatement()}.
	 *
	 * @param Context\AlterInstanceStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterInstanceStatement(Context\AlterInstanceStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterLibraryStatement()}.
	 *
	 * @param Context\AlterLibraryStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterLibraryStatement(Context\AlterLibraryStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createStatement()}.
	 *
	 * @param Context\CreateStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateStatement(Context\CreateStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createDatabase()}.
	 *
	 * @param Context\CreateDatabaseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateDatabase(Context\CreateDatabaseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createDatabaseOption()}.
	 *
	 * @param Context\CreateDatabaseOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateDatabaseOption(Context\CreateDatabaseOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTable()}.
	 *
	 * @param Context\CreateTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTable(Context\CreateTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::temporaryOrExternal()}.
	 *
	 * @param Context\TemporaryOrExternalContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTemporaryOrExternal(Context\TemporaryOrExternalContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableElementList()}.
	 *
	 * @param Context\TableElementListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableElementList(Context\TableElementListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableElement()}.
	 *
	 * @param Context\TableElementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableElement(Context\TableElementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::duplicateAsQe()}.
	 *
	 * @param Context\DuplicateAsQeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDuplicateAsQe(Context\DuplicateAsQeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::asCreateQueryExpression()}.
	 *
	 * @param Context\AsCreateQueryExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAsCreateQueryExpression(Context\AsCreateQueryExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::queryExpressionOrParens()}.
	 *
	 * @param Context\QueryExpressionOrParensContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQueryExpressionOrParens(Context\QueryExpressionOrParensContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::queryExpressionWithOptLockingClauses()}.
	 *
	 * @param Context\QueryExpressionWithOptLockingClausesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQueryExpressionWithOptLockingClauses(Context\QueryExpressionWithOptLockingClausesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createRoutine()}.
	 *
	 * @param Context\CreateRoutineContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateRoutine(Context\CreateRoutineContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createProcedure()}.
	 *
	 * @param Context\CreateProcedureContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateProcedure(Context\CreateProcedureContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::libLanguageOrComment()}.
	 *
	 * @param Context\LibLanguageOrCommentContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLibLanguageOrComment(Context\LibLanguageOrCommentContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::libraryName()}.
	 *
	 * @param Context\LibraryNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLibraryName(Context\LibraryNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::libraryRef()}.
	 *
	 * @param Context\LibraryRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLibraryRef(Context\LibraryRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::routineString()}.
	 *
	 * @param Context\RoutineStringContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoutineString(Context\RoutineStringContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::storedRoutineBody()}.
	 *
	 * @param Context\StoredRoutineBodyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStoredRoutineBody(Context\StoredRoutineBodyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createFunction()}.
	 *
	 * @param Context\CreateFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateFunction(Context\CreateFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUdf()}.
	 *
	 * @param Context\CreateUdfContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUdf(Context\CreateUdfContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::routineCreateOption()}.
	 *
	 * @param Context\RoutineCreateOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoutineCreateOption(Context\RoutineCreateOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::libraryList()}.
	 *
	 * @param Context\LibraryListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLibraryList(Context\LibraryListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::libraryNameWithAlias()}.
	 *
	 * @param Context\LibraryNameWithAliasContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLibraryNameWithAlias(Context\LibraryNameWithAliasContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::routineAlterOptions()}.
	 *
	 * @param Context\RoutineAlterOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoutineAlterOptions(Context\RoutineAlterOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::routineOption()}.
	 *
	 * @param Context\RoutineOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoutineOption(Context\RoutineOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::routineAlterOption()}.
	 *
	 * @param Context\RoutineAlterOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoutineAlterOption(Context\RoutineAlterOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::routineSuid()}.
	 *
	 * @param Context\RoutineSuidContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoutineSuid(Context\RoutineSuidContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createIndex()}.
	 *
	 * @param Context\CreateIndexContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateIndex(Context\CreateIndexContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexNameAndType()}.
	 *
	 * @param Context\IndexNameAndTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexNameAndType(Context\IndexNameAndTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createIndexTarget()}.
	 *
	 * @param Context\CreateIndexTargetContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateIndexTarget(Context\CreateIndexTargetContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createLogfileGroup()}.
	 *
	 * @param Context\CreateLogfileGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateLogfileGroup(Context\CreateLogfileGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::logfileGroupOptions()}.
	 *
	 * @param Context\LogfileGroupOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLogfileGroupOptions(Context\LogfileGroupOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::logfileGroupOption()}.
	 *
	 * @param Context\LogfileGroupOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLogfileGroupOption(Context\LogfileGroupOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createServer()}.
	 *
	 * @param Context\CreateServerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateServer(Context\CreateServerContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::serverOptions()}.
	 *
	 * @param Context\ServerOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitServerOptions(Context\ServerOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::serverOption()}.
	 *
	 * @param Context\ServerOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitServerOption(Context\ServerOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTablespace()}.
	 *
	 * @param Context\CreateTablespaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTablespace(Context\CreateTablespaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUndoTablespace()}.
	 *
	 * @param Context\CreateUndoTablespaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUndoTablespace(Context\CreateUndoTablespaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createLibraryStatement()}.
	 *
	 * @param Context\CreateLibraryStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateLibraryStatement(Context\CreateLibraryStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsDataFileName()}.
	 *
	 * @param Context\TsDataFileNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsDataFileName(Context\TsDataFileNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsDataFile()}.
	 *
	 * @param Context\TsDataFileContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsDataFile(Context\TsDataFileContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tablespaceOptions()}.
	 *
	 * @param Context\TablespaceOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTablespaceOptions(Context\TablespaceOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tablespaceOption()}.
	 *
	 * @param Context\TablespaceOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTablespaceOption(Context\TablespaceOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionInitialSize()}.
	 *
	 * @param Context\TsOptionInitialSizeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionInitialSize(Context\TsOptionInitialSizeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionUndoRedoBufferSize()}.
	 *
	 * @param Context\TsOptionUndoRedoBufferSizeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionUndoRedoBufferSize(Context\TsOptionUndoRedoBufferSizeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionAutoextendSize()}.
	 *
	 * @param Context\TsOptionAutoextendSizeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionAutoextendSize(Context\TsOptionAutoextendSizeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionMaxSize()}.
	 *
	 * @param Context\TsOptionMaxSizeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionMaxSize(Context\TsOptionMaxSizeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionExtentSize()}.
	 *
	 * @param Context\TsOptionExtentSizeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionExtentSize(Context\TsOptionExtentSizeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionNodegroup()}.
	 *
	 * @param Context\TsOptionNodegroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionNodegroup(Context\TsOptionNodegroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionEngine()}.
	 *
	 * @param Context\TsOptionEngineContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionEngine(Context\TsOptionEngineContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionWait()}.
	 *
	 * @param Context\TsOptionWaitContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionWait(Context\TsOptionWaitContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionComment()}.
	 *
	 * @param Context\TsOptionCommentContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionComment(Context\TsOptionCommentContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionFileblockSize()}.
	 *
	 * @param Context\TsOptionFileblockSizeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionFileblockSize(Context\TsOptionFileblockSizeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionEncryption()}.
	 *
	 * @param Context\TsOptionEncryptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionEncryption(Context\TsOptionEncryptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tsOptionEngineAttribute()}.
	 *
	 * @param Context\TsOptionEngineAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTsOptionEngineAttribute(Context\TsOptionEngineAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createView()}.
	 *
	 * @param Context\CreateViewContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateView(Context\CreateViewContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewPrefix()}.
	 *
	 * @param Context\ViewPrefixContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewPrefix(Context\ViewPrefixContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewReplaceOrAlgorithm()}.
	 *
	 * @param Context\ViewReplaceOrAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewReplaceOrAlgorithm(Context\ViewReplaceOrAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewAlgorithmOrMaterialization()}.
	 *
	 * @param Context\ViewAlgorithmOrMaterializationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewAlgorithmOrMaterialization(Context\ViewAlgorithmOrMaterializationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewAlgorithm()}.
	 *
	 * @param Context\ViewAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewAlgorithm(Context\ViewAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewMaterialization()}.
	 *
	 * @param Context\ViewMaterializationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewMaterialization(Context\ViewMaterializationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewSuid()}.
	 *
	 * @param Context\ViewSuidContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewSuid(Context\ViewSuidContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jsonDuality()}.
	 *
	 * @param Context\JsonDualityContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJsonDuality(Context\JsonDualityContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTrigger()}.
	 *
	 * @param Context\CreateTriggerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTrigger(Context\CreateTriggerContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::triggerFollowsPrecedesClause()}.
	 *
	 * @param Context\TriggerFollowsPrecedesClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTriggerFollowsPrecedesClause(Context\TriggerFollowsPrecedesClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createEvent()}.
	 *
	 * @param Context\CreateEventContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateEvent(Context\CreateEventContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createRole()}.
	 *
	 * @param Context\CreateRoleContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateRole(Context\CreateRoleContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createSpatialReference()}.
	 *
	 * @param Context\CreateSpatialReferenceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateSpatialReference(Context\CreateSpatialReferenceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::srsAttribute()}.
	 *
	 * @param Context\SrsAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSrsAttribute(Context\SrsAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropStatement()}.
	 *
	 * @param Context\DropStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropStatement(Context\DropStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropDatabase()}.
	 *
	 * @param Context\DropDatabaseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropDatabase(Context\DropDatabaseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropEvent()}.
	 *
	 * @param Context\DropEventContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropEvent(Context\DropEventContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropFunction()}.
	 *
	 * @param Context\DropFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropFunction(Context\DropFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropProcedure()}.
	 *
	 * @param Context\DropProcedureContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropProcedure(Context\DropProcedureContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropIndex()}.
	 *
	 * @param Context\DropIndexContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropIndex(Context\DropIndexContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropLogfileGroup()}.
	 *
	 * @param Context\DropLogfileGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropLogfileGroup(Context\DropLogfileGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropLogfileGroupOption()}.
	 *
	 * @param Context\DropLogfileGroupOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropLogfileGroupOption(Context\DropLogfileGroupOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropServer()}.
	 *
	 * @param Context\DropServerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropServer(Context\DropServerContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropTable()}.
	 *
	 * @param Context\DropTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropTable(Context\DropTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropTableSpace()}.
	 *
	 * @param Context\DropTableSpaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropTableSpace(Context\DropTableSpaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropTrigger()}.
	 *
	 * @param Context\DropTriggerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropTrigger(Context\DropTriggerContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropView()}.
	 *
	 * @param Context\DropViewContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropView(Context\DropViewContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropRole()}.
	 *
	 * @param Context\DropRoleContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropRole(Context\DropRoleContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropSpatialReference()}.
	 *
	 * @param Context\DropSpatialReferenceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropSpatialReference(Context\DropSpatialReferenceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropUndoTablespace()}.
	 *
	 * @param Context\DropUndoTablespaceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropUndoTablespace(Context\DropUndoTablespaceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropLibraryStatement()}.
	 *
	 * @param Context\DropLibraryStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropLibraryStatement(Context\DropLibraryStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::renameTableStatement()}.
	 *
	 * @param Context\RenameTableStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRenameTableStatement(Context\RenameTableStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::renamePair()}.
	 *
	 * @param Context\RenamePairContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRenamePair(Context\RenamePairContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::truncateTableStatement()}.
	 *
	 * @param Context\TruncateTableStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTruncateTableStatement(Context\TruncateTableStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::importStatement()}.
	 *
	 * @param Context\ImportStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitImportStatement(Context\ImportStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::callStatement()}.
	 *
	 * @param Context\CallStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCallStatement(Context\CallStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::deleteStatement()}.
	 *
	 * @param Context\DeleteStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDeleteStatement(Context\DeleteStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionDelete()}.
	 *
	 * @param Context\PartitionDeleteContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionDelete(Context\PartitionDeleteContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::deleteStatementOption()}.
	 *
	 * @param Context\DeleteStatementOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDeleteStatementOption(Context\DeleteStatementOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::doStatement()}.
	 *
	 * @param Context\DoStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDoStatement(Context\DoStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::handlerStatement()}.
	 *
	 * @param Context\HandlerStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHandlerStatement(Context\HandlerStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::handlerReadOrScan()}.
	 *
	 * @param Context\HandlerReadOrScanContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHandlerReadOrScan(Context\HandlerReadOrScanContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertStatement()}.
	 *
	 * @param Context\InsertStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertStatement(Context\InsertStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertLockOption()}.
	 *
	 * @param Context\InsertLockOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertLockOption(Context\InsertLockOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertFromConstructor()}.
	 *
	 * @param Context\InsertFromConstructorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertFromConstructor(Context\InsertFromConstructorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fields()}.
	 *
	 * @param Context\FieldsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFields(Context\FieldsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertValues()}.
	 *
	 * @param Context\InsertValuesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertValues(Context\InsertValuesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertQueryExpression()}.
	 *
	 * @param Context\InsertQueryExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertQueryExpression(Context\InsertQueryExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::valueList()}.
	 *
	 * @param Context\ValueListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitValueList(Context\ValueListContext $context);

	/**
	 * Visit a parse tree produced by the `values` labeled alternative
	 * in {@see MySQLParser::exprexprexprexprexprboolPriboolPriboolPriboolPripredicateOperationspredicateOperationspredicateOperationspredicateOperationssimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprsimpleExprpartitionTypeDefpartitionTypeDefpartitionTypeDef()}.
	 *
	 * @param Context\ValuesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitValues(Context\ValuesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::valuesReference()}.
	 *
	 * @param Context\ValuesReferenceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitValuesReference(Context\ValuesReferenceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertUpdateList()}.
	 *
	 * @param Context\InsertUpdateListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertUpdateList(Context\InsertUpdateListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadStatement()}.
	 *
	 * @param Context\LoadStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadStatement(Context\LoadStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dataOrXml()}.
	 *
	 * @param Context\DataOrXmlContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDataOrXml(Context\DataOrXmlContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadDataLock()}.
	 *
	 * @param Context\LoadDataLockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadDataLock(Context\LoadDataLockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadFrom()}.
	 *
	 * @param Context\LoadFromContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadFrom(Context\LoadFromContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadSourceType()}.
	 *
	 * @param Context\LoadSourceTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadSourceType(Context\LoadSourceTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceCount()}.
	 *
	 * @param Context\SourceCountContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceCount(Context\SourceCountContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceOrder()}.
	 *
	 * @param Context\SourceOrderContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceOrder(Context\SourceOrderContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::xmlRowsIdentifiedBy()}.
	 *
	 * @param Context\XmlRowsIdentifiedByContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitXmlRowsIdentifiedBy(Context\XmlRowsIdentifiedByContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ignoreLines()}.
	 *
	 * @param Context\IgnoreLinesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIgnoreLines(Context\IgnoreLinesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadDataFileTargetList()}.
	 *
	 * @param Context\LoadDataFileTargetListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadDataFileTargetList(Context\LoadDataFileTargetListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadDataSetSpec()}.
	 *
	 * @param Context\LoadDataSetSpecContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadDataSetSpec(Context\LoadDataSetSpecContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldOrVariableList()}.
	 *
	 * @param Context\FieldOrVariableListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldOrVariableList(Context\FieldOrVariableListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadAlgorithm()}.
	 *
	 * @param Context\LoadAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadAlgorithm(Context\LoadAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::compressionAlgorithm()}.
	 *
	 * @param Context\CompressionAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCompressionAlgorithm(Context\CompressionAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadParallel()}.
	 *
	 * @param Context\LoadParallelContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadParallel(Context\LoadParallelContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadMemory()}.
	 *
	 * @param Context\LoadMemoryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadMemory(Context\LoadMemoryContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replaceStatement()}.
	 *
	 * @param Context\ReplaceStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplaceStatement(Context\ReplaceStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::selectStatement()}.
	 *
	 * @param Context\SelectStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSelectStatement(Context\SelectStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::selectStatementWithInto()}.
	 *
	 * @param Context\SelectStatementWithIntoContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSelectStatementWithInto(Context\SelectStatementWithIntoContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::queryExpression()}.
	 *
	 * @param Context\QueryExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQueryExpression(Context\QueryExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::queryExpressionBody()}.
	 *
	 * @param Context\QueryExpressionBodyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQueryExpressionBody(Context\QueryExpressionBodyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::queryExpressionParens()}.
	 *
	 * @param Context\QueryExpressionParensContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQueryExpressionParens(Context\QueryExpressionParensContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::queryPrimary()}.
	 *
	 * @param Context\QueryPrimaryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQueryPrimary(Context\QueryPrimaryContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::querySpecification()}.
	 *
	 * @param Context\QuerySpecificationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQuerySpecification(Context\QuerySpecificationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::subquery()}.
	 *
	 * @param Context\SubqueryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSubquery(Context\SubqueryContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::querySpecOption()}.
	 *
	 * @param Context\QuerySpecOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQuerySpecOption(Context\QuerySpecOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::limitClause()}.
	 *
	 * @param Context\LimitClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLimitClause(Context\LimitClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::simpleLimitClause()}.
	 *
	 * @param Context\SimpleLimitClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleLimitClause(Context\SimpleLimitClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::limitOptions()}.
	 *
	 * @param Context\LimitOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLimitOptions(Context\LimitOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::limitOption()}.
	 *
	 * @param Context\LimitOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLimitOption(Context\LimitOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::outfileURI()}.
	 *
	 * @param Context\OutfileURIContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOutfileURI(Context\OutfileURIContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::outfileFileInfo()}.
	 *
	 * @param Context\OutfileFileInfoContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOutfileFileInfo(Context\OutfileFileInfoContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::outfileFileInfoList()}.
	 *
	 * @param Context\OutfileFileInfoListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOutfileFileInfoList(Context\OutfileFileInfoListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::outfileFileInfoElem()}.
	 *
	 * @param Context\OutfileFileInfoElemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOutfileFileInfoElem(Context\OutfileFileInfoElemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::intoClause()}.
	 *
	 * @param Context\IntoClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIntoClause(Context\IntoClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::procedureAnalyseClause()}.
	 *
	 * @param Context\ProcedureAnalyseClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitProcedureAnalyseClause(Context\ProcedureAnalyseClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::havingClause()}.
	 *
	 * @param Context\HavingClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHavingClause(Context\HavingClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::qualifyClause()}.
	 *
	 * @param Context\QualifyClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQualifyClause(Context\QualifyClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowClause()}.
	 *
	 * @param Context\WindowClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowClause(Context\WindowClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowDefinition()}.
	 *
	 * @param Context\WindowDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowDefinition(Context\WindowDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowSpec()}.
	 *
	 * @param Context\WindowSpecContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowSpec(Context\WindowSpecContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowSpecDetails()}.
	 *
	 * @param Context\WindowSpecDetailsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowSpecDetails(Context\WindowSpecDetailsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameClause()}.
	 *
	 * @param Context\WindowFrameClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameClause(Context\WindowFrameClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameUnits()}.
	 *
	 * @param Context\WindowFrameUnitsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameUnits(Context\WindowFrameUnitsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameExtent()}.
	 *
	 * @param Context\WindowFrameExtentContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameExtent(Context\WindowFrameExtentContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameStart()}.
	 *
	 * @param Context\WindowFrameStartContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameStart(Context\WindowFrameStartContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameBetween()}.
	 *
	 * @param Context\WindowFrameBetweenContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameBetween(Context\WindowFrameBetweenContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameBound()}.
	 *
	 * @param Context\WindowFrameBoundContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameBound(Context\WindowFrameBoundContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFrameExclusion()}.
	 *
	 * @param Context\WindowFrameExclusionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFrameExclusion(Context\WindowFrameExclusionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::withClause()}.
	 *
	 * @param Context\WithClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWithClause(Context\WithClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::commonTableExpression()}.
	 *
	 * @param Context\CommonTableExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCommonTableExpression(Context\CommonTableExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupByClause()}.
	 *
	 * @param Context\GroupByClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupByClause(Context\GroupByClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::simpleGroupingExprList()}.
	 *
	 * @param Context\SimpleGroupingExprListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleGroupingExprList(Context\SimpleGroupingExprListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::olapOption()}.
	 *
	 * @param Context\OlapOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOlapOption(Context\OlapOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::orderClause()}.
	 *
	 * @param Context\OrderClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOrderClause(Context\OrderClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::direction()}.
	 *
	 * @param Context\DirectionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDirection(Context\DirectionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fromClause()}.
	 *
	 * @param Context\FromClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFromClause(Context\FromClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableReferenceList()}.
	 *
	 * @param Context\TableReferenceListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableReferenceList(Context\TableReferenceListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableValueConstructor()}.
	 *
	 * @param Context\TableValueConstructorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableValueConstructor(Context\TableValueConstructorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::explicitTable()}.
	 *
	 * @param Context\ExplicitTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExplicitTable(Context\ExplicitTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::rowValueExplicit()}.
	 *
	 * @param Context\RowValueExplicitContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRowValueExplicit(Context\RowValueExplicitContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::selectOption()}.
	 *
	 * @param Context\SelectOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSelectOption(Context\SelectOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockingClauseList()}.
	 *
	 * @param Context\LockingClauseListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockingClauseList(Context\LockingClauseListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockingClause()}.
	 *
	 * @param Context\LockingClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockingClause(Context\LockingClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockStrengh()}.
	 *
	 * @param Context\LockStrenghContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockStrengh(Context\LockStrenghContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockedRowAction()}.
	 *
	 * @param Context\LockedRowActionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockedRowAction(Context\LockedRowActionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::selectItemList()}.
	 *
	 * @param Context\SelectItemListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSelectItemList(Context\SelectItemListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::selectItem()}.
	 *
	 * @param Context\SelectItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSelectItem(Context\SelectItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::selectAlias()}.
	 *
	 * @param Context\SelectAliasContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSelectAlias(Context\SelectAliasContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::whereClause()}.
	 *
	 * @param Context\WhereClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWhereClause(Context\WhereClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableReference()}.
	 *
	 * @param Context\TableReferenceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableReference(Context\TableReferenceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::escapedTableReference()}.
	 *
	 * @param Context\EscapedTableReferenceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEscapedTableReference(Context\EscapedTableReferenceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::joinedTable()}.
	 *
	 * @param Context\JoinedTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJoinedTable(Context\JoinedTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::naturalJoinType()}.
	 *
	 * @param Context\NaturalJoinTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNaturalJoinType(Context\NaturalJoinTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::innerJoinType()}.
	 *
	 * @param Context\InnerJoinTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInnerJoinType(Context\InnerJoinTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::outerJoinType()}.
	 *
	 * @param Context\OuterJoinTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOuterJoinType(Context\OuterJoinTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableFactor()}.
	 *
	 * @param Context\TableFactorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableFactor(Context\TableFactorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::singleTable()}.
	 *
	 * @param Context\SingleTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSingleTable(Context\SingleTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::singleTableParens()}.
	 *
	 * @param Context\SingleTableParensContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSingleTableParens(Context\SingleTableParensContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::derivedTable()}.
	 *
	 * @param Context\DerivedTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDerivedTable(Context\DerivedTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableReferenceListParens()}.
	 *
	 * @param Context\TableReferenceListParensContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableReferenceListParens(Context\TableReferenceListParensContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableFunction()}.
	 *
	 * @param Context\TableFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableFunction(Context\TableFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnsClause()}.
	 *
	 * @param Context\ColumnsClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnsClause(Context\ColumnsClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jtColumn()}.
	 *
	 * @param Context\JtColumnContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJtColumn(Context\JtColumnContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::onEmptyOrError()}.
	 *
	 * @param Context\OnEmptyOrErrorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOnEmptyOrError(Context\OnEmptyOrErrorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::onEmptyOrErrorJsonTable()}.
	 *
	 * @param Context\OnEmptyOrErrorJsonTableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOnEmptyOrErrorJsonTable(Context\OnEmptyOrErrorJsonTableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::onEmpty()}.
	 *
	 * @param Context\OnEmptyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOnEmpty(Context\OnEmptyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::onError()}.
	 *
	 * @param Context\OnErrorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOnError(Context\OnErrorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jsonOnResponse()}.
	 *
	 * @param Context\JsonOnResponseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJsonOnResponse(Context\JsonOnResponseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::unionOption()}.
	 *
	 * @param Context\UnionOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUnionOption(Context\UnionOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableAlias()}.
	 *
	 * @param Context\TableAliasContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableAlias(Context\TableAliasContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexHintList()}.
	 *
	 * @param Context\IndexHintListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexHintList(Context\IndexHintListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexHint()}.
	 *
	 * @param Context\IndexHintContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexHint(Context\IndexHintContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexHintType()}.
	 *
	 * @param Context\IndexHintTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexHintType(Context\IndexHintTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyOrIndex()}.
	 *
	 * @param Context\KeyOrIndexContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyOrIndex(Context\KeyOrIndexContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::constraintKeyType()}.
	 *
	 * @param Context\ConstraintKeyTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitConstraintKeyType(Context\ConstraintKeyTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexHintClause()}.
	 *
	 * @param Context\IndexHintClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexHintClause(Context\IndexHintClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexList()}.
	 *
	 * @param Context\IndexListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexList(Context\IndexListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexListElement()}.
	 *
	 * @param Context\IndexListElementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexListElement(Context\IndexListElementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::updateStatement()}.
	 *
	 * @param Context\UpdateStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUpdateStatement(Context\UpdateStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::transactionOrLockingStatement()}.
	 *
	 * @param Context\TransactionOrLockingStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTransactionOrLockingStatement(Context\TransactionOrLockingStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::transactionStatement()}.
	 *
	 * @param Context\TransactionStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTransactionStatement(Context\TransactionStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::beginWork()}.
	 *
	 * @param Context\BeginWorkContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitBeginWork(Context\BeginWorkContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::startTransactionOptionList()}.
	 *
	 * @param Context\StartTransactionOptionListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStartTransactionOptionList(Context\StartTransactionOptionListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::savepointStatement()}.
	 *
	 * @param Context\SavepointStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSavepointStatement(Context\SavepointStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockStatement()}.
	 *
	 * @param Context\LockStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockStatement(Context\LockStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockItem()}.
	 *
	 * @param Context\LockItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockItem(Context\LockItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lockOption()}.
	 *
	 * @param Context\LockOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLockOption(Context\LockOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::xaStatement()}.
	 *
	 * @param Context\XaStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitXaStatement(Context\XaStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::xaConvert()}.
	 *
	 * @param Context\XaConvertContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitXaConvert(Context\XaConvertContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::xid()}.
	 *
	 * @param Context\XidContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitXid(Context\XidContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replicationStatement()}.
	 *
	 * @param Context\ReplicationStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplicationStatement(Context\ReplicationStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::purgeOptions()}.
	 *
	 * @param Context\PurgeOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPurgeOptions(Context\PurgeOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resetOption()}.
	 *
	 * @param Context\ResetOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResetOption(Context\ResetOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::masterOrBinaryLogsAndGtids()}.
	 *
	 * @param Context\MasterOrBinaryLogsAndGtidsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitMasterOrBinaryLogsAndGtids(Context\MasterOrBinaryLogsAndGtidsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceResetOptions()}.
	 *
	 * @param Context\SourceResetOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceResetOptions(Context\SourceResetOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replicationLoad()}.
	 *
	 * @param Context\ReplicationLoadContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplicationLoad(Context\ReplicationLoadContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replicationSource()}.
	 *
	 * @param Context\ReplicationSourceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplicationSource(Context\ReplicationSourceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSource()}.
	 *
	 * @param Context\ChangeReplicationSourceContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSource(Context\ChangeReplicationSourceContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceDefinitions()}.
	 *
	 * @param Context\SourceDefinitionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceDefinitions(Context\SourceDefinitionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceDefinition()}.
	 *
	 * @param Context\SourceDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceDefinition(Context\SourceDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceAutoPosition()}.
	 *
	 * @param Context\ChangeReplicationSourceAutoPositionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceAutoPosition(Context\ChangeReplicationSourceAutoPositionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceHost()}.
	 *
	 * @param Context\ChangeReplicationSourceHostContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceHost(Context\ChangeReplicationSourceHostContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceBind()}.
	 *
	 * @param Context\ChangeReplicationSourceBindContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceBind(Context\ChangeReplicationSourceBindContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceUser()}.
	 *
	 * @param Context\ChangeReplicationSourceUserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceUser(Context\ChangeReplicationSourceUserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourcePassword()}.
	 *
	 * @param Context\ChangeReplicationSourcePasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourcePassword(Context\ChangeReplicationSourcePasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourcePort()}.
	 *
	 * @param Context\ChangeReplicationSourcePortContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourcePort(Context\ChangeReplicationSourcePortContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceConnectRetry()}.
	 *
	 * @param Context\ChangeReplicationSourceConnectRetryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceConnectRetry(Context\ChangeReplicationSourceConnectRetryContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceRetryCount()}.
	 *
	 * @param Context\ChangeReplicationSourceRetryCountContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceRetryCount(Context\ChangeReplicationSourceRetryCountContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceDelay()}.
	 *
	 * @param Context\ChangeReplicationSourceDelayContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceDelay(Context\ChangeReplicationSourceDelayContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSL()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSL(Context\ChangeReplicationSourceSSLContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLCA()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLCAContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLCA(Context\ChangeReplicationSourceSSLCAContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLCApath()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLCApathContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLCApath(Context\ChangeReplicationSourceSSLCApathContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLCipher()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLCipherContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLCipher(Context\ChangeReplicationSourceSSLCipherContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLCLR()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLCLRContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLCLR(Context\ChangeReplicationSourceSSLCLRContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLCLRpath()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLCLRpathContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLCLRpath(Context\ChangeReplicationSourceSSLCLRpathContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLKey()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLKeyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLKey(Context\ChangeReplicationSourceSSLKeyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLVerifyServerCert()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLVerifyServerCertContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLVerifyServerCert(Context\ChangeReplicationSourceSSLVerifyServerCertContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceTLSVersion()}.
	 *
	 * @param Context\ChangeReplicationSourceTLSVersionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceTLSVersion(Context\ChangeReplicationSourceTLSVersionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceTLSCiphersuites()}.
	 *
	 * @param Context\ChangeReplicationSourceTLSCiphersuitesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceTLSCiphersuites(Context\ChangeReplicationSourceTLSCiphersuitesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceSSLCert()}.
	 *
	 * @param Context\ChangeReplicationSourceSSLCertContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceSSLCert(Context\ChangeReplicationSourceSSLCertContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourcePublicKey()}.
	 *
	 * @param Context\ChangeReplicationSourcePublicKeyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourcePublicKey(Context\ChangeReplicationSourcePublicKeyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceGetSourcePublicKey()}.
	 *
	 * @param Context\ChangeReplicationSourceGetSourcePublicKeyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceGetSourcePublicKey(Context\ChangeReplicationSourceGetSourcePublicKeyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceHeartbeatPeriod()}.
	 *
	 * @param Context\ChangeReplicationSourceHeartbeatPeriodContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceHeartbeatPeriod(Context\ChangeReplicationSourceHeartbeatPeriodContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceCompressionAlgorithm()}.
	 *
	 * @param Context\ChangeReplicationSourceCompressionAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceCompressionAlgorithm(Context\ChangeReplicationSourceCompressionAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationSourceZstdCompressionLevel()}.
	 *
	 * @param Context\ChangeReplicationSourceZstdCompressionLevelContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationSourceZstdCompressionLevel(Context\ChangeReplicationSourceZstdCompressionLevelContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::privilegeCheckDef()}.
	 *
	 * @param Context\PrivilegeCheckDefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPrivilegeCheckDef(Context\PrivilegeCheckDefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tablePrimaryKeyCheckDef()}.
	 *
	 * @param Context\TablePrimaryKeyCheckDefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTablePrimaryKeyCheckDef(Context\TablePrimaryKeyCheckDefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::assignGtidsToAnonymousTransactionsDefinition()}.
	 *
	 * @param Context\AssignGtidsToAnonymousTransactionsDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAssignGtidsToAnonymousTransactionsDefinition(Context\AssignGtidsToAnonymousTransactionsDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceTlsCiphersuitesDef()}.
	 *
	 * @param Context\SourceTlsCiphersuitesDefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceTlsCiphersuitesDef(Context\SourceTlsCiphersuitesDefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceFileDef()}.
	 *
	 * @param Context\SourceFileDefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceFileDef(Context\SourceFileDefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceLogFile()}.
	 *
	 * @param Context\SourceLogFileContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceLogFile(Context\SourceLogFileContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sourceLogPos()}.
	 *
	 * @param Context\SourceLogPosContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSourceLogPos(Context\SourceLogPosContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::serverIdList()}.
	 *
	 * @param Context\ServerIdListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitServerIdList(Context\ServerIdListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::changeReplicationFilter()}.
	 *
	 * @param Context\ChangeReplicationFilterContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChangeReplicationFilter(Context\ChangeReplicationFilterContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterDefinition()}.
	 *
	 * @param Context\FilterDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterDefinition(Context\FilterDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterDbList()}.
	 *
	 * @param Context\FilterDbListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterDbList(Context\FilterDbListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterTableList()}.
	 *
	 * @param Context\FilterTableListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterTableList(Context\FilterTableListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterStringList()}.
	 *
	 * @param Context\FilterStringListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterStringList(Context\FilterStringListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterWildDbTableString()}.
	 *
	 * @param Context\FilterWildDbTableStringContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterWildDbTableString(Context\FilterWildDbTableStringContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterDbPairList()}.
	 *
	 * @param Context\FilterDbPairListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterDbPairList(Context\FilterDbPairListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::startReplicaStatement()}.
	 *
	 * @param Context\StartReplicaStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStartReplicaStatement(Context\StartReplicaStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::stopReplicaStatement()}.
	 *
	 * @param Context\StopReplicaStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStopReplicaStatement(Context\StopReplicaStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replicaUntil()}.
	 *
	 * @param Context\ReplicaUntilContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplicaUntil(Context\ReplicaUntilContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userOption()}.
	 *
	 * @param Context\UserOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserOption(Context\UserOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::passwordOption()}.
	 *
	 * @param Context\PasswordOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPasswordOption(Context\PasswordOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::defaultAuthOption()}.
	 *
	 * @param Context\DefaultAuthOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDefaultAuthOption(Context\DefaultAuthOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::pluginDirOption()}.
	 *
	 * @param Context\PluginDirOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPluginDirOption(Context\PluginDirOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replicaThreadOptions()}.
	 *
	 * @param Context\ReplicaThreadOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplicaThreadOptions(Context\ReplicaThreadOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replicaThreadOption()}.
	 *
	 * @param Context\ReplicaThreadOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplicaThreadOption(Context\ReplicaThreadOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupReplication()}.
	 *
	 * @param Context\GroupReplicationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupReplication(Context\GroupReplicationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupReplicationStartOptions()}.
	 *
	 * @param Context\GroupReplicationStartOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupReplicationStartOptions(Context\GroupReplicationStartOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupReplicationStartOption()}.
	 *
	 * @param Context\GroupReplicationStartOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupReplicationStartOption(Context\GroupReplicationStartOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupReplicationUser()}.
	 *
	 * @param Context\GroupReplicationUserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupReplicationUser(Context\GroupReplicationUserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupReplicationPassword()}.
	 *
	 * @param Context\GroupReplicationPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupReplicationPassword(Context\GroupReplicationPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupReplicationPluginAuth()}.
	 *
	 * @param Context\GroupReplicationPluginAuthContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupReplicationPluginAuth(Context\GroupReplicationPluginAuthContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replica()}.
	 *
	 * @param Context\ReplicaContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplica(Context\ReplicaContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::preparedStatement()}.
	 *
	 * @param Context\PreparedStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPreparedStatement(Context\PreparedStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::executeStatement()}.
	 *
	 * @param Context\ExecuteStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExecuteStatement(Context\ExecuteStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::executeVarList()}.
	 *
	 * @param Context\ExecuteVarListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExecuteVarList(Context\ExecuteVarListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::cloneStatement()}.
	 *
	 * @param Context\CloneStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCloneStatement(Context\CloneStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dataDirSSL()}.
	 *
	 * @param Context\DataDirSSLContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDataDirSSL(Context\DataDirSSLContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ssl()}.
	 *
	 * @param Context\SslContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSsl(Context\SslContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::accountManagementStatement()}.
	 *
	 * @param Context\AccountManagementStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAccountManagementStatement(Context\AccountManagementStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterUserStatement()}.
	 *
	 * @param Context\AlterUserStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterUserStatement(Context\AlterUserStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterUserList()}.
	 *
	 * @param Context\AlterUserListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterUserList(Context\AlterUserListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterUser()}.
	 *
	 * @param Context\AlterUserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterUser(Context\AlterUserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::oldAlterUser()}.
	 *
	 * @param Context\OldAlterUserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOldAlterUser(Context\OldAlterUserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userFunction()}.
	 *
	 * @param Context\UserFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserFunction(Context\UserFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUserStatement()}.
	 *
	 * @param Context\CreateUserStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUserStatement(Context\CreateUserStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUserTail()}.
	 *
	 * @param Context\CreateUserTailContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUserTail(Context\CreateUserTailContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userAttributes()}.
	 *
	 * @param Context\UserAttributesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserAttributes(Context\UserAttributesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::defaultRoleClause()}.
	 *
	 * @param Context\DefaultRoleClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDefaultRoleClause(Context\DefaultRoleClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::requireClause()}.
	 *
	 * @param Context\RequireClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRequireClause(Context\RequireClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::connectOptions()}.
	 *
	 * @param Context\ConnectOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitConnectOptions(Context\ConnectOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::accountLockPasswordExpireOptions()}.
	 *
	 * @param Context\AccountLockPasswordExpireOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAccountLockPasswordExpireOptions(Context\AccountLockPasswordExpireOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userAttribute()}.
	 *
	 * @param Context\UserAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserAttribute(Context\UserAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropUserStatement()}.
	 *
	 * @param Context\DropUserStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropUserStatement(Context\DropUserStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::grantStatement()}.
	 *
	 * @param Context\GrantStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGrantStatement(Context\GrantStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::grantTargetList()}.
	 *
	 * @param Context\GrantTargetListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGrantTargetList(Context\GrantTargetListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::grantOptions()}.
	 *
	 * @param Context\GrantOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGrantOptions(Context\GrantOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::exceptRoleList()}.
	 *
	 * @param Context\ExceptRoleListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExceptRoleList(Context\ExceptRoleListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::withRoles()}.
	 *
	 * @param Context\WithRolesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWithRoles(Context\WithRolesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::grantAs()}.
	 *
	 * @param Context\GrantAsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGrantAs(Context\GrantAsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::versionedRequireClause()}.
	 *
	 * @param Context\VersionedRequireClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitVersionedRequireClause(Context\VersionedRequireClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::renameUserStatement()}.
	 *
	 * @param Context\RenameUserStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRenameUserStatement(Context\RenameUserStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::revokeStatement()}.
	 *
	 * @param Context\RevokeStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRevokeStatement(Context\RevokeStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::aclType()}.
	 *
	 * @param Context\AclTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAclType(Context\AclTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleOrPrivilegesList()}.
	 *
	 * @param Context\RoleOrPrivilegesListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleOrPrivilegesList(Context\RoleOrPrivilegesListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleOrPrivilege()}.
	 *
	 * @param Context\RoleOrPrivilegeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleOrPrivilege(Context\RoleOrPrivilegeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::grantIdentifier()}.
	 *
	 * @param Context\GrantIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGrantIdentifier(Context\GrantIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::requireList()}.
	 *
	 * @param Context\RequireListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRequireList(Context\RequireListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::requireListElement()}.
	 *
	 * @param Context\RequireListElementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRequireListElement(Context\RequireListElementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::grantOption()}.
	 *
	 * @param Context\GrantOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGrantOption(Context\GrantOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::setRoleStatement()}.
	 *
	 * @param Context\SetRoleStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSetRoleStatement(Context\SetRoleStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleList()}.
	 *
	 * @param Context\RoleListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleList(Context\RoleListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::role()}.
	 *
	 * @param Context\RoleContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRole(Context\RoleContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableAdministrationStatement()}.
	 *
	 * @param Context\TableAdministrationStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableAdministrationStatement(Context\TableAdministrationStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::histogramAutoUpdate()}.
	 *
	 * @param Context\HistogramAutoUpdateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHistogramAutoUpdate(Context\HistogramAutoUpdateContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::histogramUpdateParam()}.
	 *
	 * @param Context\HistogramUpdateParamContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHistogramUpdateParam(Context\HistogramUpdateParamContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::histogramNumBuckets()}.
	 *
	 * @param Context\HistogramNumBucketsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHistogramNumBuckets(Context\HistogramNumBucketsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::histogram()}.
	 *
	 * @param Context\HistogramContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHistogram(Context\HistogramContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::checkOption()}.
	 *
	 * @param Context\CheckOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCheckOption(Context\CheckOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::repairType()}.
	 *
	 * @param Context\RepairTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRepairType(Context\RepairTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::uninstallStatement()}.
	 *
	 * @param Context\UninstallStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUninstallStatement(Context\UninstallStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::installStatement()}.
	 *
	 * @param Context\InstallStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInstallStatement(Context\InstallStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::installOptionType()}.
	 *
	 * @param Context\InstallOptionTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInstallOptionType(Context\InstallOptionTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::installSetRvalue()}.
	 *
	 * @param Context\InstallSetRvalueContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInstallSetRvalue(Context\InstallSetRvalueContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::installSetValue()}.
	 *
	 * @param Context\InstallSetValueContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInstallSetValue(Context\InstallSetValueContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::installSetValueList()}.
	 *
	 * @param Context\InstallSetValueListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInstallSetValueList(Context\InstallSetValueListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::setStatement()}.
	 *
	 * @param Context\SetStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSetStatement(Context\SetStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::startOptionValueList()}.
	 *
	 * @param Context\StartOptionValueListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStartOptionValueList(Context\StartOptionValueListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::transactionCharacteristics()}.
	 *
	 * @param Context\TransactionCharacteristicsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTransactionCharacteristics(Context\TransactionCharacteristicsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::transactionAccessMode()}.
	 *
	 * @param Context\TransactionAccessModeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTransactionAccessMode(Context\TransactionAccessModeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::isolationLevel()}.
	 *
	 * @param Context\IsolationLevelContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIsolationLevel(Context\IsolationLevelContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::optionValueListContinued()}.
	 *
	 * @param Context\OptionValueListContinuedContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOptionValueListContinued(Context\OptionValueListContinuedContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::optionValueNoOptionType()}.
	 *
	 * @param Context\OptionValueNoOptionTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOptionValueNoOptionType(Context\OptionValueNoOptionTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::optionValue()}.
	 *
	 * @param Context\OptionValueContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOptionValue(Context\OptionValueContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::setSystemVariable()}.
	 *
	 * @param Context\SetSystemVariableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSetSystemVariable(Context\SetSystemVariableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::startOptionValueListFollowingOptionType()}.
	 *
	 * @param Context\StartOptionValueListFollowingOptionTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStartOptionValueListFollowingOptionType(Context\StartOptionValueListFollowingOptionTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::optionValueFollowingOptionType()}.
	 *
	 * @param Context\OptionValueFollowingOptionTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOptionValueFollowingOptionType(Context\OptionValueFollowingOptionTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::setExprOrDefault()}.
	 *
	 * @param Context\SetExprOrDefaultContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSetExprOrDefault(Context\SetExprOrDefaultContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showDatabasesStatement()}.
	 *
	 * @param Context\ShowDatabasesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowDatabasesStatement(Context\ShowDatabasesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showTablesStatement()}.
	 *
	 * @param Context\ShowTablesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowTablesStatement(Context\ShowTablesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showTriggersStatement()}.
	 *
	 * @param Context\ShowTriggersStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowTriggersStatement(Context\ShowTriggersStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showEventsStatement()}.
	 *
	 * @param Context\ShowEventsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowEventsStatement(Context\ShowEventsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showTableStatusStatement()}.
	 *
	 * @param Context\ShowTableStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowTableStatusStatement(Context\ShowTableStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showOpenTablesStatement()}.
	 *
	 * @param Context\ShowOpenTablesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowOpenTablesStatement(Context\ShowOpenTablesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showParseTreeStatement()}.
	 *
	 * @param Context\ShowParseTreeStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowParseTreeStatement(Context\ShowParseTreeStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showPluginsStatement()}.
	 *
	 * @param Context\ShowPluginsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowPluginsStatement(Context\ShowPluginsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showEngineLogsStatement()}.
	 *
	 * @param Context\ShowEngineLogsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowEngineLogsStatement(Context\ShowEngineLogsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showEngineMutexStatement()}.
	 *
	 * @param Context\ShowEngineMutexStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowEngineMutexStatement(Context\ShowEngineMutexStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showEngineStatusStatement()}.
	 *
	 * @param Context\ShowEngineStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowEngineStatusStatement(Context\ShowEngineStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showColumnsStatement()}.
	 *
	 * @param Context\ShowColumnsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowColumnsStatement(Context\ShowColumnsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showBinaryLogsStatement()}.
	 *
	 * @param Context\ShowBinaryLogsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowBinaryLogsStatement(Context\ShowBinaryLogsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showBinaryLogStatusStatement()}.
	 *
	 * @param Context\ShowBinaryLogStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowBinaryLogStatusStatement(Context\ShowBinaryLogStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showReplicasStatement()}.
	 *
	 * @param Context\ShowReplicasStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowReplicasStatement(Context\ShowReplicasStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showBinlogEventsStatement()}.
	 *
	 * @param Context\ShowBinlogEventsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowBinlogEventsStatement(Context\ShowBinlogEventsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showRelaylogEventsStatement()}.
	 *
	 * @param Context\ShowRelaylogEventsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowRelaylogEventsStatement(Context\ShowRelaylogEventsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showKeysStatement()}.
	 *
	 * @param Context\ShowKeysStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowKeysStatement(Context\ShowKeysStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showLibraryStatusStatement()}.
	 *
	 * @param Context\ShowLibraryStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowLibraryStatusStatement(Context\ShowLibraryStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showEnginesStatement()}.
	 *
	 * @param Context\ShowEnginesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowEnginesStatement(Context\ShowEnginesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCountWarningsStatement()}.
	 *
	 * @param Context\ShowCountWarningsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCountWarningsStatement(Context\ShowCountWarningsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCountErrorsStatement()}.
	 *
	 * @param Context\ShowCountErrorsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCountErrorsStatement(Context\ShowCountErrorsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showWarningsStatement()}.
	 *
	 * @param Context\ShowWarningsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowWarningsStatement(Context\ShowWarningsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showErrorsStatement()}.
	 *
	 * @param Context\ShowErrorsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowErrorsStatement(Context\ShowErrorsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showProfilesStatement()}.
	 *
	 * @param Context\ShowProfilesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowProfilesStatement(Context\ShowProfilesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showProfileStatement()}.
	 *
	 * @param Context\ShowProfileStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowProfileStatement(Context\ShowProfileStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showStatusStatement()}.
	 *
	 * @param Context\ShowStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowStatusStatement(Context\ShowStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showProcessListStatement()}.
	 *
	 * @param Context\ShowProcessListStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowProcessListStatement(Context\ShowProcessListStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showVariablesStatement()}.
	 *
	 * @param Context\ShowVariablesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowVariablesStatement(Context\ShowVariablesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCharacterSetStatement()}.
	 *
	 * @param Context\ShowCharacterSetStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCharacterSetStatement(Context\ShowCharacterSetStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCollationStatement()}.
	 *
	 * @param Context\ShowCollationStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCollationStatement(Context\ShowCollationStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showPrivilegesStatement()}.
	 *
	 * @param Context\ShowPrivilegesStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowPrivilegesStatement(Context\ShowPrivilegesStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showGrantsStatement()}.
	 *
	 * @param Context\ShowGrantsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowGrantsStatement(Context\ShowGrantsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateDatabaseStatement()}.
	 *
	 * @param Context\ShowCreateDatabaseStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateDatabaseStatement(Context\ShowCreateDatabaseStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateTableStatement()}.
	 *
	 * @param Context\ShowCreateTableStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateTableStatement(Context\ShowCreateTableStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateViewStatement()}.
	 *
	 * @param Context\ShowCreateViewStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateViewStatement(Context\ShowCreateViewStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showMasterStatusStatement()}.
	 *
	 * @param Context\ShowMasterStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowMasterStatusStatement(Context\ShowMasterStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showReplicaStatusStatement()}.
	 *
	 * @param Context\ShowReplicaStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowReplicaStatusStatement(Context\ShowReplicaStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateProcedureStatement()}.
	 *
	 * @param Context\ShowCreateProcedureStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateProcedureStatement(Context\ShowCreateProcedureStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateFunctionStatement()}.
	 *
	 * @param Context\ShowCreateFunctionStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateFunctionStatement(Context\ShowCreateFunctionStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateLibraryStatement()}.
	 *
	 * @param Context\ShowCreateLibraryStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateLibraryStatement(Context\ShowCreateLibraryStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateTriggerStatement()}.
	 *
	 * @param Context\ShowCreateTriggerStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateTriggerStatement(Context\ShowCreateTriggerStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateProcedureStatusStatement()}.
	 *
	 * @param Context\ShowCreateProcedureStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateProcedureStatusStatement(Context\ShowCreateProcedureStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateFunctionStatusStatement()}.
	 *
	 * @param Context\ShowCreateFunctionStatusStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateFunctionStatusStatement(Context\ShowCreateFunctionStatusStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateProcedureCodeStatement()}.
	 *
	 * @param Context\ShowCreateProcedureCodeStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateProcedureCodeStatement(Context\ShowCreateProcedureCodeStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateFunctionCodeStatement()}.
	 *
	 * @param Context\ShowCreateFunctionCodeStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateFunctionCodeStatement(Context\ShowCreateFunctionCodeStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateEventStatement()}.
	 *
	 * @param Context\ShowCreateEventStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateEventStatement(Context\ShowCreateEventStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCreateUserStatement()}.
	 *
	 * @param Context\ShowCreateUserStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCreateUserStatement(Context\ShowCreateUserStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::showCommandType()}.
	 *
	 * @param Context\ShowCommandTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitShowCommandType(Context\ShowCommandTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::engineOrAll()}.
	 *
	 * @param Context\EngineOrAllContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEngineOrAll(Context\EngineOrAllContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fromOrIn()}.
	 *
	 * @param Context\FromOrInContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFromOrIn(Context\FromOrInContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::inDb()}.
	 *
	 * @param Context\InDbContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInDb(Context\InDbContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::profileDefinitions()}.
	 *
	 * @param Context\ProfileDefinitionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitProfileDefinitions(Context\ProfileDefinitionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::profileDefinition()}.
	 *
	 * @param Context\ProfileDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitProfileDefinition(Context\ProfileDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::otherAdministrativeStatement()}.
	 *
	 * @param Context\OtherAdministrativeStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOtherAdministrativeStatement(Context\OtherAdministrativeStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyCacheListOrParts()}.
	 *
	 * @param Context\KeyCacheListOrPartsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyCacheListOrParts(Context\KeyCacheListOrPartsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyCacheList()}.
	 *
	 * @param Context\KeyCacheListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyCacheList(Context\KeyCacheListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::assignToKeycache()}.
	 *
	 * @param Context\AssignToKeycacheContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAssignToKeycache(Context\AssignToKeycacheContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::assignToKeycachePartition()}.
	 *
	 * @param Context\AssignToKeycachePartitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAssignToKeycachePartition(Context\AssignToKeycachePartitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::cacheKeyList()}.
	 *
	 * @param Context\CacheKeyListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCacheKeyList(Context\CacheKeyListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyUsageElement()}.
	 *
	 * @param Context\KeyUsageElementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyUsageElement(Context\KeyUsageElementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyUsageList()}.
	 *
	 * @param Context\KeyUsageListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyUsageList(Context\KeyUsageListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::flushOption()}.
	 *
	 * @param Context\FlushOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFlushOption(Context\FlushOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::logType()}.
	 *
	 * @param Context\LogTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLogType(Context\LogTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::flushTables()}.
	 *
	 * @param Context\FlushTablesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFlushTables(Context\FlushTablesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::flushTablesOptions()}.
	 *
	 * @param Context\FlushTablesOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFlushTablesOptions(Context\FlushTablesOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::preloadTail()}.
	 *
	 * @param Context\PreloadTailContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPreloadTail(Context\PreloadTailContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::preloadList()}.
	 *
	 * @param Context\PreloadListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPreloadList(Context\PreloadListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::preloadKeys()}.
	 *
	 * @param Context\PreloadKeysContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPreloadKeys(Context\PreloadKeysContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::adminPartition()}.
	 *
	 * @param Context\AdminPartitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAdminPartition(Context\AdminPartitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resourceGroupManagement()}.
	 *
	 * @param Context\ResourceGroupManagementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResourceGroupManagement(Context\ResourceGroupManagementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createResourceGroup()}.
	 *
	 * @param Context\CreateResourceGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateResourceGroup(Context\CreateResourceGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resourceGroupVcpuList()}.
	 *
	 * @param Context\ResourceGroupVcpuListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResourceGroupVcpuList(Context\ResourceGroupVcpuListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::vcpuNumOrRange()}.
	 *
	 * @param Context\VcpuNumOrRangeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitVcpuNumOrRange(Context\VcpuNumOrRangeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resourceGroupPriority()}.
	 *
	 * @param Context\ResourceGroupPriorityContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResourceGroupPriority(Context\ResourceGroupPriorityContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resourceGroupEnableDisable()}.
	 *
	 * @param Context\ResourceGroupEnableDisableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResourceGroupEnableDisable(Context\ResourceGroupEnableDisableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::alterResourceGroup()}.
	 *
	 * @param Context\AlterResourceGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAlterResourceGroup(Context\AlterResourceGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::setResourceGroup()}.
	 *
	 * @param Context\SetResourceGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSetResourceGroup(Context\SetResourceGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::threadIdList()}.
	 *
	 * @param Context\ThreadIdListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitThreadIdList(Context\ThreadIdListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dropResourceGroup()}.
	 *
	 * @param Context\DropResourceGroupContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDropResourceGroup(Context\DropResourceGroupContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::utilityStatement()}.
	 *
	 * @param Context\UtilityStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUtilityStatement(Context\UtilityStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::describeStatement()}.
	 *
	 * @param Context\DescribeStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDescribeStatement(Context\DescribeStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::explainStatement()}.
	 *
	 * @param Context\ExplainStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExplainStatement(Context\ExplainStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::explainOptions()}.
	 *
	 * @param Context\ExplainOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExplainOptions(Context\ExplainOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::explainableStatement()}.
	 *
	 * @param Context\ExplainableStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExplainableStatement(Context\ExplainableStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::explainInto()}.
	 *
	 * @param Context\ExplainIntoContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExplainInto(Context\ExplainIntoContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::helpCommand()}.
	 *
	 * @param Context\HelpCommandContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHelpCommand(Context\HelpCommandContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::useCommand()}.
	 *
	 * @param Context\UseCommandContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUseCommand(Context\UseCommandContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::restartServer()}.
	 *
	 * @param Context\RestartServerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRestartServer(Context\RestartServerContext $context);

	/**
	 * Visit a parse tree produced by the `exprOr` labeled alternative
	 * in {@see MySQLParser::expr()}.
	 *
	 * @param Context\ExprOrContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprOr(Context\ExprOrContext $context);

	/**
	 * Visit a parse tree produced by the `exprNot` labeled alternative
	 * in {@see MySQLParser::expr()}.
	 *
	 * @param Context\ExprNotContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprNot(Context\ExprNotContext $context);

	/**
	 * Visit a parse tree produced by the `exprIs` labeled alternative
	 * in {@see MySQLParser::expr()}.
	 *
	 * @param Context\ExprIsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprIs(Context\ExprIsContext $context);

	/**
	 * Visit a parse tree produced by the `exprAnd` labeled alternative
	 * in {@see MySQLParser::expr()}.
	 *
	 * @param Context\ExprAndContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprAnd(Context\ExprAndContext $context);

	/**
	 * Visit a parse tree produced by the `exprXor` labeled alternative
	 * in {@see MySQLParser::expr()}.
	 *
	 * @param Context\ExprXorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprXor(Context\ExprXorContext $context);

	/**
	 * Visit a parse tree produced by the `primaryExprPredicate` labeled alternative
	 * in {@see MySQLParser::boolPri()}.
	 *
	 * @param Context\PrimaryExprPredicateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPrimaryExprPredicate(Context\PrimaryExprPredicateContext $context);

	/**
	 * Visit a parse tree produced by the `primaryExprCompare` labeled alternative
	 * in {@see MySQLParser::boolPri()}.
	 *
	 * @param Context\PrimaryExprCompareContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPrimaryExprCompare(Context\PrimaryExprCompareContext $context);

	/**
	 * Visit a parse tree produced by the `primaryExprAllAny` labeled alternative
	 * in {@see MySQLParser::boolPri()}.
	 *
	 * @param Context\PrimaryExprAllAnyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPrimaryExprAllAny(Context\PrimaryExprAllAnyContext $context);

	/**
	 * Visit a parse tree produced by the `primaryExprIsNull` labeled alternative
	 * in {@see MySQLParser::boolPri()}.
	 *
	 * @param Context\PrimaryExprIsNullContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPrimaryExprIsNull(Context\PrimaryExprIsNullContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::compOp()}.
	 *
	 * @param Context\CompOpContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCompOp(Context\CompOpContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::predicate()}.
	 *
	 * @param Context\PredicateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPredicate(Context\PredicateContext $context);

	/**
	 * Visit a parse tree produced by the `predicateExprIn` labeled alternative
	 * in {@see MySQLParser::predicateOperations()}.
	 *
	 * @param Context\PredicateExprInContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPredicateExprIn(Context\PredicateExprInContext $context);

	/**
	 * Visit a parse tree produced by the `predicateExprBetween` labeled alternative
	 * in {@see MySQLParser::predicateOperations()}.
	 *
	 * @param Context\PredicateExprBetweenContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPredicateExprBetween(Context\PredicateExprBetweenContext $context);

	/**
	 * Visit a parse tree produced by the `predicateExprLike` labeled alternative
	 * in {@see MySQLParser::predicateOperations()}.
	 *
	 * @param Context\PredicateExprLikeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPredicateExprLike(Context\PredicateExprLikeContext $context);

	/**
	 * Visit a parse tree produced by the `predicateExprRegex` labeled alternative
	 * in {@see MySQLParser::predicateOperations()}.
	 *
	 * @param Context\PredicateExprRegexContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPredicateExprRegex(Context\PredicateExprRegexContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::bitExpr()}.
	 *
	 * @param Context\BitExprContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitBitExpr(Context\BitExprContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprConvert` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprConvertContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprConvert(Context\SimpleExprConvertContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprCast` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprCastContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprCast(Context\SimpleExprCastContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprGenericFunction` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprGenericFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprGenericFunction(Context\SimpleExprGenericFunctionContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprUnary` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprUnaryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprUnary(Context\SimpleExprUnaryContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExpressionRValue` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExpressionRValueContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExpressionRValue(Context\SimpleExpressionRValueContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprOdbc` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprOdbcContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprOdbc(Context\SimpleExprOdbcContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprRuntimeFunction` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprRuntimeFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprRuntimeFunction(Context\SimpleExprRuntimeFunctionContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprCollate` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprCollateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprCollate(Context\SimpleExprCollateContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprMatch` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprMatchContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprMatch(Context\SimpleExprMatchContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprWindowingFunction` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprWindowingFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprWindowingFunction(Context\SimpleExprWindowingFunctionContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprBinary` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprBinaryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprBinary(Context\SimpleExprBinaryContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprColumnRef` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprColumnRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprColumnRef(Context\SimpleExprColumnRefContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprParamMarker` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprParamMarkerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprParamMarker(Context\SimpleExprParamMarkerContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprSum` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprSumContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprSum(Context\SimpleExprSumContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprCastTime` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprCastTimeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprCastTime(Context\SimpleExprCastTimeContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprConvertUsing` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprConvertUsingContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprConvertUsing(Context\SimpleExprConvertUsingContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprSubQuery` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprSubQueryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprSubQuery(Context\SimpleExprSubQueryContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprGroupingOperation` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprGroupingOperationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprGroupingOperation(Context\SimpleExprGroupingOperationContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprNot` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprNotContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprNot(Context\SimpleExprNotContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprValues` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprValuesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprValues(Context\SimpleExprValuesContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprUserVariableAssignment` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprUserVariableAssignmentContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprUserVariableAssignment(Context\SimpleExprUserVariableAssignmentContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprDefault` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprDefaultContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprDefault(Context\SimpleExprDefaultContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprList` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprList(Context\SimpleExprListContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprInterval` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprIntervalContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprInterval(Context\SimpleExprIntervalContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprCase` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprCaseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprCase(Context\SimpleExprCaseContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprConcat` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprConcatContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprConcat(Context\SimpleExprConcatContext $context);

	/**
	 * Visit a parse tree produced by the `simpleExprLiteral` labeled alternative
	 * in {@see MySQLParser::simpleExpr()}.
	 *
	 * @param Context\SimpleExprLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprLiteral(Context\SimpleExprLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::arrayCast()}.
	 *
	 * @param Context\ArrayCastContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitArrayCast(Context\ArrayCastContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jsonOperator()}.
	 *
	 * @param Context\JsonOperatorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJsonOperator(Context\JsonOperatorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sumExpr()}.
	 *
	 * @param Context\SumExprContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSumExpr(Context\SumExprContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupingOperation()}.
	 *
	 * @param Context\GroupingOperationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupingOperation(Context\GroupingOperationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowFunctionCall()}.
	 *
	 * @param Context\WindowFunctionCallContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowFunctionCall(Context\WindowFunctionCallContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::samplingMethod()}.
	 *
	 * @param Context\SamplingMethodContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSamplingMethod(Context\SamplingMethodContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::samplingPercentage()}.
	 *
	 * @param Context\SamplingPercentageContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSamplingPercentage(Context\SamplingPercentageContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tablesampleClause()}.
	 *
	 * @param Context\TablesampleClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTablesampleClause(Context\TablesampleClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowingClause()}.
	 *
	 * @param Context\WindowingClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowingClause(Context\WindowingClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::leadLagInfo()}.
	 *
	 * @param Context\LeadLagInfoContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLeadLagInfo(Context\LeadLagInfoContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::stableInteger()}.
	 *
	 * @param Context\StableIntegerContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStableInteger(Context\StableIntegerContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::paramOrVar()}.
	 *
	 * @param Context\ParamOrVarContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitParamOrVar(Context\ParamOrVarContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::nullTreatment()}.
	 *
	 * @param Context\NullTreatmentContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNullTreatment(Context\NullTreatmentContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jsonFunction()}.
	 *
	 * @param Context\JsonFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJsonFunction(Context\JsonFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jsonConstructorNullClause()}.
	 *
	 * @param Context\JsonConstructorNullClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJsonConstructorNullClause(Context\JsonConstructorNullClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::inSumExpr()}.
	 *
	 * @param Context\InSumExprContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInSumExpr(Context\InSumExprContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identListArg()}.
	 *
	 * @param Context\IdentListArgContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentListArg(Context\IdentListArgContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identList()}.
	 *
	 * @param Context\IdentListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentList(Context\IdentListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fulltextOptions()}.
	 *
	 * @param Context\FulltextOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFulltextOptions(Context\FulltextOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::runtimeFunctionCall()}.
	 *
	 * @param Context\RuntimeFunctionCallContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRuntimeFunctionCall(Context\RuntimeFunctionCallContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jdvWithTableTags()}.
	 *
	 * @param Context\JdvWithTableTagsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJdvWithTableTags(Context\JdvWithTableTagsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jdvTableTag()}.
	 *
	 * @param Context\JdvTableTagContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJdvTableTag(Context\JdvTableTagContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jdvTableTags()}.
	 *
	 * @param Context\JdvTableTagsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJdvTableTags(Context\JdvTableTagsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jdvNameValueList()}.
	 *
	 * @param Context\JdvNameValueListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJdvNameValueList(Context\JdvNameValueListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jdvNameValue()}.
	 *
	 * @param Context\JdvNameValueContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJdvNameValue(Context\JdvNameValueContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::returningType()}.
	 *
	 * @param Context\ReturningTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReturningType(Context\ReturningTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::geometryFunction()}.
	 *
	 * @param Context\GeometryFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGeometryFunction(Context\GeometryFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::timeFunctionParameters()}.
	 *
	 * @param Context\TimeFunctionParametersContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTimeFunctionParameters(Context\TimeFunctionParametersContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fractionalPrecision()}.
	 *
	 * @param Context\FractionalPrecisionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFractionalPrecision(Context\FractionalPrecisionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::weightStringLevels()}.
	 *
	 * @param Context\WeightStringLevelsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWeightStringLevels(Context\WeightStringLevelsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::weightStringLevelListItem()}.
	 *
	 * @param Context\WeightStringLevelListItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWeightStringLevelListItem(Context\WeightStringLevelListItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dateTimeTtype()}.
	 *
	 * @param Context\DateTimeTtypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDateTimeTtype(Context\DateTimeTtypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::trimFunction()}.
	 *
	 * @param Context\TrimFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTrimFunction(Context\TrimFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::substringFunction()}.
	 *
	 * @param Context\SubstringFunctionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSubstringFunction(Context\SubstringFunctionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::functionCallGeneric()}.
	 *
	 * @param Context\FunctionCallGenericContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFunctionCallGeneric(Context\FunctionCallGenericContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::udfExprList()}.
	 *
	 * @param Context\UdfExprListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUdfExprList(Context\UdfExprListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::udfExpr()}.
	 *
	 * @param Context\UdfExprContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUdfExpr(Context\UdfExprContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userVariable()}.
	 *
	 * @param Context\UserVariableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserVariable(Context\UserVariableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::inExpressionUserVariableAssignment()}.
	 *
	 * @param Context\InExpressionUserVariableAssignmentContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInExpressionUserVariableAssignment(Context\InExpressionUserVariableAssignmentContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::rvalueSystemOrUserVariable()}.
	 *
	 * @param Context\RvalueSystemOrUserVariableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRvalueSystemOrUserVariable(Context\RvalueSystemOrUserVariableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lvalueVariable()}.
	 *
	 * @param Context\LvalueVariableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLvalueVariable(Context\LvalueVariableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::rvalueSystemVariable()}.
	 *
	 * @param Context\RvalueSystemVariableContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRvalueSystemVariable(Context\RvalueSystemVariableContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::whenExpression()}.
	 *
	 * @param Context\WhenExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWhenExpression(Context\WhenExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::thenExpression()}.
	 *
	 * @param Context\ThenExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitThenExpression(Context\ThenExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::elseExpression()}.
	 *
	 * @param Context\ElseExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitElseExpression(Context\ElseExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::castType()}.
	 *
	 * @param Context\CastTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCastType(Context\CastTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::exprList()}.
	 *
	 * @param Context\ExprListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprList(Context\ExprListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::charset()}.
	 *
	 * @param Context\CharsetContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCharset(Context\CharsetContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::notRule()}.
	 *
	 * @param Context\NotRuleContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNotRule(Context\NotRuleContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::not2Rule()}.
	 *
	 * @param Context\Not2RuleContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNot2Rule(Context\Not2RuleContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::interval()}.
	 *
	 * @param Context\IntervalContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInterval(Context\IntervalContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::intervalTimeStamp()}.
	 *
	 * @param Context\IntervalTimeStampContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIntervalTimeStamp(Context\IntervalTimeStampContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::exprListWithParentheses()}.
	 *
	 * @param Context\ExprListWithParenthesesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprListWithParentheses(Context\ExprListWithParenthesesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::exprWithParentheses()}.
	 *
	 * @param Context\ExprWithParenthesesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExprWithParentheses(Context\ExprWithParenthesesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::simpleExprWithParentheses()}.
	 *
	 * @param Context\SimpleExprWithParenthesesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleExprWithParentheses(Context\SimpleExprWithParenthesesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::orderList()}.
	 *
	 * @param Context\OrderListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOrderList(Context\OrderListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::orderExpression()}.
	 *
	 * @param Context\OrderExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOrderExpression(Context\OrderExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupList()}.
	 *
	 * @param Context\GroupListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupList(Context\GroupListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupingExpression()}.
	 *
	 * @param Context\GroupingExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupingExpression(Context\GroupingExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::emptyGroupingSet()}.
	 *
	 * @param Context\EmptyGroupingSetContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEmptyGroupingSet(Context\EmptyGroupingSetContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::groupingSetList()}.
	 *
	 * @param Context\GroupingSetListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGroupingSetList(Context\GroupingSetListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::channel()}.
	 *
	 * @param Context\ChannelContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitChannel(Context\ChannelContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::compoundStatement()}.
	 *
	 * @param Context\CompoundStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCompoundStatement(Context\CompoundStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::returnStatement()}.
	 *
	 * @param Context\ReturnStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReturnStatement(Context\ReturnStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ifStatement()}.
	 *
	 * @param Context\IfStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIfStatement(Context\IfStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ifBody()}.
	 *
	 * @param Context\IfBodyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIfBody(Context\IfBodyContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::thenStatement()}.
	 *
	 * @param Context\ThenStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitThenStatement(Context\ThenStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::compoundStatementList()}.
	 *
	 * @param Context\CompoundStatementListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCompoundStatementList(Context\CompoundStatementListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::caseStatement()}.
	 *
	 * @param Context\CaseStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCaseStatement(Context\CaseStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::elseStatement()}.
	 *
	 * @param Context\ElseStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitElseStatement(Context\ElseStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::labeledBlock()}.
	 *
	 * @param Context\LabeledBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLabeledBlock(Context\LabeledBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::unlabeledBlock()}.
	 *
	 * @param Context\UnlabeledBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUnlabeledBlock(Context\UnlabeledBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::label()}.
	 *
	 * @param Context\LabelContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLabel(Context\LabelContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::beginEndBlock()}.
	 *
	 * @param Context\BeginEndBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitBeginEndBlock(Context\BeginEndBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::labeledControl()}.
	 *
	 * @param Context\LabeledControlContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLabeledControl(Context\LabeledControlContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::unlabeledControl()}.
	 *
	 * @param Context\UnlabeledControlContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUnlabeledControl(Context\UnlabeledControlContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loopBlock()}.
	 *
	 * @param Context\LoopBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoopBlock(Context\LoopBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::whileDoBlock()}.
	 *
	 * @param Context\WhileDoBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWhileDoBlock(Context\WhileDoBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::repeatUntilBlock()}.
	 *
	 * @param Context\RepeatUntilBlockContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRepeatUntilBlock(Context\RepeatUntilBlockContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::spDeclarations()}.
	 *
	 * @param Context\SpDeclarationsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSpDeclarations(Context\SpDeclarationsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::spDeclaration()}.
	 *
	 * @param Context\SpDeclarationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSpDeclaration(Context\SpDeclarationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::variableDeclaration()}.
	 *
	 * @param Context\VariableDeclarationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitVariableDeclaration(Context\VariableDeclarationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::conditionDeclaration()}.
	 *
	 * @param Context\ConditionDeclarationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitConditionDeclaration(Context\ConditionDeclarationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::spCondition()}.
	 *
	 * @param Context\SpConditionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSpCondition(Context\SpConditionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sqlstate()}.
	 *
	 * @param Context\SqlstateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSqlstate(Context\SqlstateContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::handlerDeclaration()}.
	 *
	 * @param Context\HandlerDeclarationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHandlerDeclaration(Context\HandlerDeclarationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::handlerCondition()}.
	 *
	 * @param Context\HandlerConditionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitHandlerCondition(Context\HandlerConditionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::cursorDeclaration()}.
	 *
	 * @param Context\CursorDeclarationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCursorDeclaration(Context\CursorDeclarationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::iterateStatement()}.
	 *
	 * @param Context\IterateStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIterateStatement(Context\IterateStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::leaveStatement()}.
	 *
	 * @param Context\LeaveStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLeaveStatement(Context\LeaveStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::getDiagnosticsStatement()}.
	 *
	 * @param Context\GetDiagnosticsStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGetDiagnosticsStatement(Context\GetDiagnosticsStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::signalAllowedExpr()}.
	 *
	 * @param Context\SignalAllowedExprContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSignalAllowedExpr(Context\SignalAllowedExprContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::statementInformationItem()}.
	 *
	 * @param Context\StatementInformationItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStatementInformationItem(Context\StatementInformationItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::conditionInformationItem()}.
	 *
	 * @param Context\ConditionInformationItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitConditionInformationItem(Context\ConditionInformationItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::signalInformationItemName()}.
	 *
	 * @param Context\SignalInformationItemNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSignalInformationItemName(Context\SignalInformationItemNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::signalStatement()}.
	 *
	 * @param Context\SignalStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSignalStatement(Context\SignalStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resignalStatement()}.
	 *
	 * @param Context\ResignalStatementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResignalStatement(Context\ResignalStatementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::signalInformationItem()}.
	 *
	 * @param Context\SignalInformationItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSignalInformationItem(Context\SignalInformationItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::cursorOpen()}.
	 *
	 * @param Context\CursorOpenContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCursorOpen(Context\CursorOpenContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::cursorClose()}.
	 *
	 * @param Context\CursorCloseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCursorClose(Context\CursorCloseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::cursorFetch()}.
	 *
	 * @param Context\CursorFetchContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCursorFetch(Context\CursorFetchContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::schedule()}.
	 *
	 * @param Context\ScheduleContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSchedule(Context\ScheduleContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnDefinition()}.
	 *
	 * @param Context\ColumnDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnDefinition(Context\ColumnDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::checkOrReferences()}.
	 *
	 * @param Context\CheckOrReferencesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCheckOrReferences(Context\CheckOrReferencesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::checkConstraint()}.
	 *
	 * @param Context\CheckConstraintContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCheckConstraint(Context\CheckConstraintContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::constraintEnforcement()}.
	 *
	 * @param Context\ConstraintEnforcementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitConstraintEnforcement(Context\ConstraintEnforcementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableConstraintDef()}.
	 *
	 * @param Context\TableConstraintDefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableConstraintDef(Context\TableConstraintDefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::constraintName()}.
	 *
	 * @param Context\ConstraintNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitConstraintName(Context\ConstraintNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldDefinition()}.
	 *
	 * @param Context\FieldDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldDefinition(Context\FieldDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnAttribute()}.
	 *
	 * @param Context\ColumnAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnAttribute(Context\ColumnAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnFormat()}.
	 *
	 * @param Context\ColumnFormatContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnFormat(Context\ColumnFormatContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::storageMedia()}.
	 *
	 * @param Context\StorageMediaContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStorageMedia(Context\StorageMediaContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::now()}.
	 *
	 * @param Context\NowContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNow(Context\NowContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::nowOrSignedLiteral()}.
	 *
	 * @param Context\NowOrSignedLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNowOrSignedLiteral(Context\NowOrSignedLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::gcolAttribute()}.
	 *
	 * @param Context\GcolAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitGcolAttribute(Context\GcolAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::references()}.
	 *
	 * @param Context\ReferencesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReferences(Context\ReferencesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::deleteOption()}.
	 *
	 * @param Context\DeleteOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDeleteOption(Context\DeleteOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyList()}.
	 *
	 * @param Context\KeyListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyList(Context\KeyListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyPart()}.
	 *
	 * @param Context\KeyPartContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyPart(Context\KeyPartContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyListWithExpression()}.
	 *
	 * @param Context\KeyListWithExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyListWithExpression(Context\KeyListWithExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::keyPartOrExpression()}.
	 *
	 * @param Context\KeyPartOrExpressionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitKeyPartOrExpression(Context\KeyPartOrExpressionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexType()}.
	 *
	 * @param Context\IndexTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexType(Context\IndexTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexOption()}.
	 *
	 * @param Context\IndexOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexOption(Context\IndexOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::commonIndexOption()}.
	 *
	 * @param Context\CommonIndexOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCommonIndexOption(Context\CommonIndexOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::visibility()}.
	 *
	 * @param Context\VisibilityContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitVisibility(Context\VisibilityContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexTypeClause()}.
	 *
	 * @param Context\IndexTypeClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexTypeClause(Context\IndexTypeClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fulltextIndexOption()}.
	 *
	 * @param Context\FulltextIndexOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFulltextIndexOption(Context\FulltextIndexOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::spatialIndexOption()}.
	 *
	 * @param Context\SpatialIndexOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSpatialIndexOption(Context\SpatialIndexOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dataTypeDefinition()}.
	 *
	 * @param Context\DataTypeDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDataTypeDefinition(Context\DataTypeDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dataType()}.
	 *
	 * @param Context\DataTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDataType(Context\DataTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::nchar()}.
	 *
	 * @param Context\NcharContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNchar(Context\NcharContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::realType()}.
	 *
	 * @param Context\RealTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRealType(Context\RealTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldLength()}.
	 *
	 * @param Context\FieldLengthContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldLength(Context\FieldLengthContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldOptions()}.
	 *
	 * @param Context\FieldOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldOptions(Context\FieldOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::charsetWithOptBinary()}.
	 *
	 * @param Context\CharsetWithOptBinaryContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCharsetWithOptBinary(Context\CharsetWithOptBinaryContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ascii()}.
	 *
	 * @param Context\AsciiContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitAscii(Context\AsciiContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::unicode()}.
	 *
	 * @param Context\UnicodeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUnicode(Context\UnicodeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::wsNumCodepoints()}.
	 *
	 * @param Context\WsNumCodepointsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWsNumCodepoints(Context\WsNumCodepointsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::typeDatetimePrecision()}.
	 *
	 * @param Context\TypeDatetimePrecisionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTypeDatetimePrecision(Context\TypeDatetimePrecisionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::functionDatetimePrecision()}.
	 *
	 * @param Context\FunctionDatetimePrecisionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFunctionDatetimePrecision(Context\FunctionDatetimePrecisionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::charsetName()}.
	 *
	 * @param Context\CharsetNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCharsetName(Context\CharsetNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::collationName()}.
	 *
	 * @param Context\CollationNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCollationName(Context\CollationNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTableOptions()}.
	 *
	 * @param Context\CreateTableOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTableOptions(Context\CreateTableOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTableOptionsEtc()}.
	 *
	 * @param Context\CreateTableOptionsEtcContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTableOptionsEtc(Context\CreateTableOptionsEtcContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createPartitioningEtc()}.
	 *
	 * @param Context\CreatePartitioningEtcContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreatePartitioningEtc(Context\CreatePartitioningEtcContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTableOptionsSpaceSeparated()}.
	 *
	 * @param Context\CreateTableOptionsSpaceSeparatedContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTableOptionsSpaceSeparated(Context\CreateTableOptionsSpaceSeparatedContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createTableOption()}.
	 *
	 * @param Context\CreateTableOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateTableOption(Context\CreateTableOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::externalFiles()}.
	 *
	 * @param Context\ExternalFilesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitExternalFiles(Context\ExternalFilesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fileAttributes()}.
	 *
	 * @param Context\FileAttributesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFileAttributes(Context\FileAttributesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fileAttribute()}.
	 *
	 * @param Context\FileAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFileAttribute(Context\FileAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ternaryOption()}.
	 *
	 * @param Context\TernaryOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTernaryOption(Context\TernaryOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::defaultCollation()}.
	 *
	 * @param Context\DefaultCollationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDefaultCollation(Context\DefaultCollationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::defaultEncryption()}.
	 *
	 * @param Context\DefaultEncryptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDefaultEncryption(Context\DefaultEncryptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::defaultCharset()}.
	 *
	 * @param Context\DefaultCharsetContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDefaultCharset(Context\DefaultCharsetContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionClause()}.
	 *
	 * @param Context\PartitionClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionClause(Context\PartitionClauseContext $context);

	/**
	 * Visit a parse tree produced by the `partitionDefKey` labeled alternative
	 * in {@see MySQLParser::partitionTypeDef()}.
	 *
	 * @param Context\PartitionDefKeyContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionDefKey(Context\PartitionDefKeyContext $context);

	/**
	 * Visit a parse tree produced by the `partitionDefHash` labeled alternative
	 * in {@see MySQLParser::partitionTypeDef()}.
	 *
	 * @param Context\PartitionDefHashContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionDefHash(Context\PartitionDefHashContext $context);

	/**
	 * Visit a parse tree produced by the `partitionDefRangeList` labeled alternative
	 * in {@see MySQLParser::partitionTypeDef()}.
	 *
	 * @param Context\PartitionDefRangeListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionDefRangeList(Context\PartitionDefRangeListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::subPartitions()}.
	 *
	 * @param Context\SubPartitionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSubPartitions(Context\SubPartitionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionKeyAlgorithm()}.
	 *
	 * @param Context\PartitionKeyAlgorithmContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionKeyAlgorithm(Context\PartitionKeyAlgorithmContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionDefinitions()}.
	 *
	 * @param Context\PartitionDefinitionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionDefinitions(Context\PartitionDefinitionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionDefinition()}.
	 *
	 * @param Context\PartitionDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionDefinition(Context\PartitionDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionValuesIn()}.
	 *
	 * @param Context\PartitionValuesInContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionValuesIn(Context\PartitionValuesInContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionOption()}.
	 *
	 * @param Context\PartitionOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionOption(Context\PartitionOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::subpartitionDefinition()}.
	 *
	 * @param Context\SubpartitionDefinitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSubpartitionDefinition(Context\SubpartitionDefinitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionValueItemListParen()}.
	 *
	 * @param Context\PartitionValueItemListParenContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionValueItemListParen(Context\PartitionValueItemListParenContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::partitionValueItem()}.
	 *
	 * @param Context\PartitionValueItemContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPartitionValueItem(Context\PartitionValueItemContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::definerClause()}.
	 *
	 * @param Context\DefinerClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDefinerClause(Context\DefinerClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ifExists()}.
	 *
	 * @param Context\IfExistsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIfExists(Context\IfExistsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ifExistsIdentifier()}.
	 *
	 * @param Context\IfExistsIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIfExistsIdentifier(Context\IfExistsIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::persistedVariableIdentifier()}.
	 *
	 * @param Context\PersistedVariableIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPersistedVariableIdentifier(Context\PersistedVariableIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ifNotExists()}.
	 *
	 * @param Context\IfNotExistsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIfNotExists(Context\IfNotExistsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ignoreUnknownUser()}.
	 *
	 * @param Context\IgnoreUnknownUserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIgnoreUnknownUser(Context\IgnoreUnknownUserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::procedureParameter()}.
	 *
	 * @param Context\ProcedureParameterContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitProcedureParameter(Context\ProcedureParameterContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::functionParameter()}.
	 *
	 * @param Context\FunctionParameterContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFunctionParameter(Context\FunctionParameterContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::collate()}.
	 *
	 * @param Context\CollateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCollate(Context\CollateContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::typeWithOptCollate()}.
	 *
	 * @param Context\TypeWithOptCollateContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTypeWithOptCollate(Context\TypeWithOptCollateContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::schemaIdentifierPair()}.
	 *
	 * @param Context\SchemaIdentifierPairContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSchemaIdentifierPair(Context\SchemaIdentifierPairContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewRefList()}.
	 *
	 * @param Context\ViewRefListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewRefList(Context\ViewRefListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadDatasetList()}.
	 *
	 * @param Context\LoadDatasetListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadDatasetList(Context\LoadDatasetListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::loadDatasetElement()}.
	 *
	 * @param Context\LoadDatasetElementContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLoadDatasetElement(Context\LoadDatasetElementContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::charsetClause()}.
	 *
	 * @param Context\CharsetClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCharsetClause(Context\CharsetClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldsClause()}.
	 *
	 * @param Context\FieldsClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldsClause(Context\FieldsClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldTerm()}.
	 *
	 * @param Context\FieldTermContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldTerm(Context\FieldTermContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::linesClause()}.
	 *
	 * @param Context\LinesClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLinesClause(Context\LinesClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lineTerm()}.
	 *
	 * @param Context\LineTermContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLineTerm(Context\LineTermContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userList()}.
	 *
	 * @param Context\UserListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserList(Context\UserListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUserList()}.
	 *
	 * @param Context\CreateUserListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUserList(Context\CreateUserListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUser()}.
	 *
	 * @param Context\CreateUserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUser(Context\CreateUserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::createUserWithMfa()}.
	 *
	 * @param Context\CreateUserWithMfaContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitCreateUserWithMfa(Context\CreateUserWithMfaContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identification()}.
	 *
	 * @param Context\IdentificationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentification(Context\IdentificationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifiedByPassword()}.
	 *
	 * @param Context\IdentifiedByPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifiedByPassword(Context\IdentifiedByPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifiedByRandomPassword()}.
	 *
	 * @param Context\IdentifiedByRandomPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifiedByRandomPassword(Context\IdentifiedByRandomPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifiedWithPlugin()}.
	 *
	 * @param Context\IdentifiedWithPluginContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifiedWithPlugin(Context\IdentifiedWithPluginContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifiedWithPluginAsAuth()}.
	 *
	 * @param Context\IdentifiedWithPluginAsAuthContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifiedWithPluginAsAuth(Context\IdentifiedWithPluginAsAuthContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifiedWithPluginByPassword()}.
	 *
	 * @param Context\IdentifiedWithPluginByPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifiedWithPluginByPassword(Context\IdentifiedWithPluginByPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifiedWithPluginByRandomPassword()}.
	 *
	 * @param Context\IdentifiedWithPluginByRandomPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifiedWithPluginByRandomPassword(Context\IdentifiedWithPluginByRandomPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::initialAuth()}.
	 *
	 * @param Context\InitialAuthContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInitialAuth(Context\InitialAuthContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::retainCurrentPassword()}.
	 *
	 * @param Context\RetainCurrentPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRetainCurrentPassword(Context\RetainCurrentPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::discardOldPassword()}.
	 *
	 * @param Context\DiscardOldPasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDiscardOldPassword(Context\DiscardOldPasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userRegistration()}.
	 *
	 * @param Context\UserRegistrationContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserRegistration(Context\UserRegistrationContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::factor()}.
	 *
	 * @param Context\FactorContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFactor(Context\FactorContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::replacePassword()}.
	 *
	 * @param Context\ReplacePasswordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReplacePassword(Context\ReplacePasswordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::userIdentifierOrText()}.
	 *
	 * @param Context\UserIdentifierOrTextContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUserIdentifierOrText(Context\UserIdentifierOrTextContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::user()}.
	 *
	 * @param Context\UserContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUser(Context\UserContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::likeClause()}.
	 *
	 * @param Context\LikeClauseContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLikeClause(Context\LikeClauseContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::likeOrWhere()}.
	 *
	 * @param Context\LikeOrWhereContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLikeOrWhere(Context\LikeOrWhereContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::onlineOption()}.
	 *
	 * @param Context\OnlineOptionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOnlineOption(Context\OnlineOptionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::noWriteToBinLog()}.
	 *
	 * @param Context\NoWriteToBinLogContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNoWriteToBinLog(Context\NoWriteToBinLogContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::usePartition()}.
	 *
	 * @param Context\UsePartitionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUsePartition(Context\UsePartitionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::fieldIdentifier()}.
	 *
	 * @param Context\FieldIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFieldIdentifier(Context\FieldIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnName()}.
	 *
	 * @param Context\ColumnNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnName(Context\ColumnNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnInternalRef()}.
	 *
	 * @param Context\ColumnInternalRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnInternalRef(Context\ColumnInternalRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnInternalRefList()}.
	 *
	 * @param Context\ColumnInternalRefListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnInternalRefList(Context\ColumnInternalRefListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::columnRef()}.
	 *
	 * @param Context\ColumnRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitColumnRef(Context\ColumnRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::insertIdentifier()}.
	 *
	 * @param Context\InsertIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInsertIdentifier(Context\InsertIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexName()}.
	 *
	 * @param Context\IndexNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexName(Context\IndexNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::indexRef()}.
	 *
	 * @param Context\IndexRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIndexRef(Context\IndexRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableWild()}.
	 *
	 * @param Context\TableWildContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableWild(Context\TableWildContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::schemaName()}.
	 *
	 * @param Context\SchemaNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSchemaName(Context\SchemaNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::schemaRef()}.
	 *
	 * @param Context\SchemaRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSchemaRef(Context\SchemaRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::procedureName()}.
	 *
	 * @param Context\ProcedureNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitProcedureName(Context\ProcedureNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::procedureRef()}.
	 *
	 * @param Context\ProcedureRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitProcedureRef(Context\ProcedureRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::functionName()}.
	 *
	 * @param Context\FunctionNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFunctionName(Context\FunctionNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::functionRef()}.
	 *
	 * @param Context\FunctionRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFunctionRef(Context\FunctionRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::triggerName()}.
	 *
	 * @param Context\TriggerNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTriggerName(Context\TriggerNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::triggerRef()}.
	 *
	 * @param Context\TriggerRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTriggerRef(Context\TriggerRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewName()}.
	 *
	 * @param Context\ViewNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewName(Context\ViewNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::viewRef()}.
	 *
	 * @param Context\ViewRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitViewRef(Context\ViewRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tablespaceName()}.
	 *
	 * @param Context\TablespaceNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTablespaceName(Context\TablespaceNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tablespaceRef()}.
	 *
	 * @param Context\TablespaceRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTablespaceRef(Context\TablespaceRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::logfileGroupName()}.
	 *
	 * @param Context\LogfileGroupNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLogfileGroupName(Context\LogfileGroupNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::logfileGroupRef()}.
	 *
	 * @param Context\LogfileGroupRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLogfileGroupRef(Context\LogfileGroupRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::eventName()}.
	 *
	 * @param Context\EventNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEventName(Context\EventNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::eventRef()}.
	 *
	 * @param Context\EventRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEventRef(Context\EventRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::udfName()}.
	 *
	 * @param Context\UdfNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUdfName(Context\UdfNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::serverName()}.
	 *
	 * @param Context\ServerNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitServerName(Context\ServerNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::serverRef()}.
	 *
	 * @param Context\ServerRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitServerRef(Context\ServerRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::engineRef()}.
	 *
	 * @param Context\EngineRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEngineRef(Context\EngineRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableName()}.
	 *
	 * @param Context\TableNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableName(Context\TableNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::filterTableRef()}.
	 *
	 * @param Context\FilterTableRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFilterTableRef(Context\FilterTableRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableRefWithWildcard()}.
	 *
	 * @param Context\TableRefWithWildcardContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableRefWithWildcard(Context\TableRefWithWildcardContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableRef()}.
	 *
	 * @param Context\TableRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableRef(Context\TableRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableRefList()}.
	 *
	 * @param Context\TableRefListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableRefList(Context\TableRefListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::tableAliasRefList()}.
	 *
	 * @param Context\TableAliasRefListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTableAliasRefList(Context\TableAliasRefListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::parameterName()}.
	 *
	 * @param Context\ParameterNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitParameterName(Context\ParameterNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::labelIdentifier()}.
	 *
	 * @param Context\LabelIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLabelIdentifier(Context\LabelIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::labelRef()}.
	 *
	 * @param Context\LabelRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLabelRef(Context\LabelRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleIdentifier()}.
	 *
	 * @param Context\RoleIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleIdentifier(Context\RoleIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::pluginRef()}.
	 *
	 * @param Context\PluginRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPluginRef(Context\PluginRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::componentRef()}.
	 *
	 * @param Context\ComponentRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitComponentRef(Context\ComponentRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::resourceGroupRef()}.
	 *
	 * @param Context\ResourceGroupRefContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitResourceGroupRef(Context\ResourceGroupRefContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::windowName()}.
	 *
	 * @param Context\WindowNameContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitWindowName(Context\WindowNameContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::pureIdentifier()}.
	 *
	 * @param Context\PureIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPureIdentifier(Context\PureIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifier()}.
	 *
	 * @param Context\IdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifier(Context\IdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierList()}.
	 *
	 * @param Context\IdentifierListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierList(Context\IdentifierListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierListWithParentheses()}.
	 *
	 * @param Context\IdentifierListWithParenthesesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierListWithParentheses(Context\IdentifierListWithParenthesesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::qualifiedIdentifier()}.
	 *
	 * @param Context\QualifiedIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitQualifiedIdentifier(Context\QualifiedIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::simpleIdentifier()}.
	 *
	 * @param Context\SimpleIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSimpleIdentifier(Context\SimpleIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::dotIdentifier()}.
	 *
	 * @param Context\DotIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitDotIdentifier(Context\DotIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ulong_number()}.
	 *
	 * @param Context\Ulong_numberContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUlong_number(Context\Ulong_numberContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::real_ulong_number()}.
	 *
	 * @param Context\Real_ulong_numberContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReal_ulong_number(Context\Real_ulong_numberContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::ulonglongNumber()}.
	 *
	 * @param Context\UlonglongNumberContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitUlonglongNumber(Context\UlonglongNumberContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::real_ulonglong_number()}.
	 *
	 * @param Context\Real_ulonglong_numberContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitReal_ulonglong_number(Context\Real_ulonglong_numberContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::signedLiteral()}.
	 *
	 * @param Context\SignedLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSignedLiteral(Context\SignedLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::signedLiteralOrNull()}.
	 *
	 * @param Context\SignedLiteralOrNullContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSignedLiteralOrNull(Context\SignedLiteralOrNullContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::literal()}.
	 *
	 * @param Context\LiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLiteral(Context\LiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::literalOrNull()}.
	 *
	 * @param Context\LiteralOrNullContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLiteralOrNull(Context\LiteralOrNullContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::nullAsLiteral()}.
	 *
	 * @param Context\NullAsLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNullAsLiteral(Context\NullAsLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::stringList()}.
	 *
	 * @param Context\StringListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStringList(Context\StringListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textStringLiteral()}.
	 *
	 * @param Context\TextStringLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextStringLiteral(Context\TextStringLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textString()}.
	 *
	 * @param Context\TextStringContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextString(Context\TextStringContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textStringHash()}.
	 *
	 * @param Context\TextStringHashContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextStringHash(Context\TextStringHashContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textLiteral()}.
	 *
	 * @param Context\TextLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextLiteral(Context\TextLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textStringNoLinebreak()}.
	 *
	 * @param Context\TextStringNoLinebreakContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextStringNoLinebreak(Context\TextStringNoLinebreakContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textStringLiteralList()}.
	 *
	 * @param Context\TextStringLiteralListContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextStringLiteralList(Context\TextStringLiteralListContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::numLiteral()}.
	 *
	 * @param Context\NumLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNumLiteral(Context\NumLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::boolLiteral()}.
	 *
	 * @param Context\BoolLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitBoolLiteral(Context\BoolLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::nullLiteral()}.
	 *
	 * @param Context\NullLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitNullLiteral(Context\NullLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::int64Literal()}.
	 *
	 * @param Context\Int64LiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitInt64Literal(Context\Int64LiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::temporalLiteral()}.
	 *
	 * @param Context\TemporalLiteralContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTemporalLiteral(Context\TemporalLiteralContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::floatOptions()}.
	 *
	 * @param Context\FloatOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitFloatOptions(Context\FloatOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::standardFloatOptions()}.
	 *
	 * @param Context\StandardFloatOptionsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitStandardFloatOptions(Context\StandardFloatOptionsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::precision()}.
	 *
	 * @param Context\PrecisionContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitPrecision(Context\PrecisionContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::textOrIdentifier()}.
	 *
	 * @param Context\TextOrIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitTextOrIdentifier(Context\TextOrIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lValueIdentifier()}.
	 *
	 * @param Context\LValueIdentifierContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLValueIdentifier(Context\LValueIdentifierContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleIdentifierOrText()}.
	 *
	 * @param Context\RoleIdentifierOrTextContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleIdentifierOrText(Context\RoleIdentifierOrTextContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::sizeNumber()}.
	 *
	 * @param Context\SizeNumberContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSizeNumber(Context\SizeNumberContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::parentheses()}.
	 *
	 * @param Context\ParenthesesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitParentheses(Context\ParenthesesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::equal()}.
	 *
	 * @param Context\EqualContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitEqual(Context\EqualContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::optionType()}.
	 *
	 * @param Context\OptionTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitOptionType(Context\OptionTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::rvalueSystemVariableType()}.
	 *
	 * @param Context\RvalueSystemVariableTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRvalueSystemVariableType(Context\RvalueSystemVariableTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::setVarIdentType()}.
	 *
	 * @param Context\SetVarIdentTypeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitSetVarIdentType(Context\SetVarIdentTypeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::jsonAttribute()}.
	 *
	 * @param Context\JsonAttributeContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitJsonAttribute(Context\JsonAttributeContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierKeyword()}.
	 *
	 * @param Context\IdentifierKeywordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierKeyword(Context\IdentifierKeywordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierKeywordsAmbiguous1RolesAndLabels()}.
	 *
	 * @param Context\IdentifierKeywordsAmbiguous1RolesAndLabelsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierKeywordsAmbiguous1RolesAndLabels(Context\IdentifierKeywordsAmbiguous1RolesAndLabelsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierKeywordsAmbiguous2Labels()}.
	 *
	 * @param Context\IdentifierKeywordsAmbiguous2LabelsContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierKeywordsAmbiguous2Labels(Context\IdentifierKeywordsAmbiguous2LabelsContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::labelKeyword()}.
	 *
	 * @param Context\LabelKeywordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLabelKeyword(Context\LabelKeywordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierKeywordsAmbiguous3Roles()}.
	 *
	 * @param Context\IdentifierKeywordsAmbiguous3RolesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierKeywordsAmbiguous3Roles(Context\IdentifierKeywordsAmbiguous3RolesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierKeywordsUnambiguous()}.
	 *
	 * @param Context\IdentifierKeywordsUnambiguousContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierKeywordsUnambiguous(Context\IdentifierKeywordsUnambiguousContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleKeyword()}.
	 *
	 * @param Context\RoleKeywordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleKeyword(Context\RoleKeywordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::lValueKeyword()}.
	 *
	 * @param Context\LValueKeywordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitLValueKeyword(Context\LValueKeywordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::identifierKeywordsAmbiguous4SystemVariables()}.
	 *
	 * @param Context\IdentifierKeywordsAmbiguous4SystemVariablesContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitIdentifierKeywordsAmbiguous4SystemVariables(Context\IdentifierKeywordsAmbiguous4SystemVariablesContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleOrIdentifierKeyword()}.
	 *
	 * @param Context\RoleOrIdentifierKeywordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleOrIdentifierKeyword(Context\RoleOrIdentifierKeywordContext $context);

	/**
	 * Visit a parse tree produced by {@see MySQLParser::roleOrLabelKeyword()}.
	 *
	 * @param Context\RoleOrLabelKeywordContext $context The parse tree.
	 *
	 * @return mixed The visitor result.
	 */
	public function visitRoleOrLabelKeyword(Context\RoleOrLabelKeywordContext $context);
}
