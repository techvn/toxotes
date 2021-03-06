<?php
use Flywheel\Model\Behavior\NestedSet;

require_once dirname(__FILE__) . '/Base/MenusBase.php';
/**
 * \Menus
 *  This class has been auto-generated at 10/07/2013 16:06:44
 * @version		$Id$
 * @package		Model
 *
 * Nested Set Behavior
 * API List
 *
 * @method int getLeftValue()
 * @method void setLeftValue()
 * @method int getRightValue()
 * @method void setRightValue()
 * @method mixed getScopeValue(bool $withQuote)
 * @method void setScopeValue(int $scope)
 * @method int getLevelValue()
 * @method void setLevelValue()
 * @method \Menus makeRoot()
 * @method boolean isInTree()
 * @method boolean isRoot()
 * @method boolean isLeaf()
 * @method boolean isDescendantOf($parent)
 * @method boolean isAncestorOf($child)
 * @method boolean hasParent()
 * @method boolean hasPrevSibling(\Flywheel\Db\Query $query = null)
 * @method boolean hasNextSibling(\Flywheel\Db\Query $query = null)
 * @method boolean hasChildren()
 * @method int countChildren(\Flywheel\Db\Query $query = null)
 * @method int countDescendants(\Flywheel\Db\Query $query = null)
 * @method null|\Menus getParent()
 * @method bool|\Menus getPrevSibling(\Flywheel\Db\Query $query = null)
 * @method bool|\Menus getNextSibling(\Flywheel\Db\Query $query = null)
 * @method array getChildren(\Flywheel\Db\Query $query = null)
 * @method null|\Menus getFirstChild(\Flywheel\Db\Query $query = null)
 * @method null|\Menus getLastChild(\Flywheel\Db\Query $query = null)
 * @method \Menus[] getSiblings($query = null)
 * @method \Menus[] getDescendants($query = null)
 * @method \Menus[] getBranch($query = null)
 * @method \Menus[] getAncestors($query = null)
 * @method \Menus addChild($node)
 * @method \Menus insertAsFirstChildOf($node)
 * @method \Menus insertAsLastChildOf($node)
 * @method \Menus insertAsPrevSiblingOf($node)
 * @method \Menus insertAsNextSiblingOf($node)
 * @method \Menus moveToFirstChildOf($node)
 * @method \Menus moveToLastChildOf($node)
 * @method \Menus moveToPrevSiblingOf($node)
 * @method \Menus moveToNextSiblingOf($node)
 * @method int deleteDescendants()
 * @method void shiftRLValues($delta, $first, $last = null, $scope = null)
 * @method void shiftLevel($delta, $first, $last = null, $scope = null)
 * @method void setNegativeScope($scope)
 * @method \Menus findRoot($scope = null)
 * @method \Menus[] findRoots()
 * @method boolean isNodeValid()
 * @method void makeRoomForLeaf(int $level, $scope = null)
 */
class Menus extends \MenusBase {
    const INTERNAL = 'internal',
        EXTERNAL = 'external',
        SEPARATE = 'separate';

    public function init() {
        parent::init();
        $this->attachBehavior('NestedSet', new NestedSet(), array(
            'left_attr' => 'lft',
            'right_attr' => 'rgt',
            'level_attr' => 'lvl'
        ));
    }

    public static function retrieveRoot() {
        $menu = new Menus();
        $root = $menu->findRoot();

        if (!$root) {
            $root = new Menus();
            $root->name = 'Root of menus';
            $root->makeRoot();
            $root->save();
        }

        return $root;
    }

    public function validationRules() {
        self::$_validate['name'][] = array(
            'name' => 'Require',
            'message' => "name can not be blank!");

        self::$_validate['type'][] = array(
            'name' => 'Require',
            'message' => 'type can not be blank!',
        );
    }
}