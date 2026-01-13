/**
 * AIKAFLOW - Frontend Node Tests
 * 
 * Run in browser console on the editor page
 */

(function() {
    console.log('=== AIKAFLOW Node Tests ===\n');
    
    const tests = [];
    let passed = 0;
    let failed = 0;
    
    function test(name, fn) {
        try {
            const result = fn();
            if (result === true) {
                console.log(`✓ ${name}`);
                passed++;
            } else {
                console.error(`✗ ${name}: ${result}`);
                failed++;
            }
        } catch (e) {
            console.error(`✗ ${name}: ${e.message}`);
            failed++;
        }
    }
    
    function assertEquals(expected, actual) {
        if (expected === actual) return true;
        return `Expected ${expected} but got ${actual}`;
    }
    
    function assertNotNull(value) {
        if (value !== null && value !== undefined) return true;
        return `Expected non-null value`;
    }
    
    // Test 1: Node definitions exist
    test('Node definitions loaded', () => {
        return assertNotNull(window.NodeDefinitions);
    });
    
    // Test 2: Node manager exists
    test('Node manager exists', () => {
        return assertNotNull(window.NodeManager);
    });
    
    // Test 3: Create node manager instance
    const nodeManager = new NodeManager();
    
    test('Create node manager', () => {
        return assertNotNull(nodeManager);
    });
    
    // Test 4: Create a node
    test('Create text-input node', () => {
        const node = nodeManager.createNode('text-input', { x: 100, y: 100 });
        return assertNotNull(node) && assertEquals('text-input', node.type);
    });
    
    // Test 5: Get node
    test('Get node by ID', () => {
        const nodes = nodeManager.getAllNodes();
        const node = nodeManager.getNode(nodes[0].id);
        return assertNotNull(node);
    });
    
    // Test 6: Update node data
    test('Update node data', () => {
        const nodes = nodeManager.getAllNodes();
        nodeManager.updateNodeData(nodes[0].id, 'text', 'Hello World');
        const node = nodeManager.getNode(nodes[0].id);
        return assertEquals('Hello World', node.data.text);
    });
    
    // Test 7: Select node
    test('Select node', () => {
        const nodes = nodeManager.getAllNodes();
        nodeManager.selectNode(nodes[0].id);
        return assertEquals(true, nodeManager.isSelected(nodes[0].id));
    });
    
    // Test 8: Clear selection
    test('Clear selection', () => {
        nodeManager.clearSelection();
        return assertEquals(0, nodeManager.selectedNodes.size);
    });
    
    // Test 9: Delete node
    test('Delete node', () => {
        const nodes = nodeManager.getAllNodes();
        const id = nodes[0].id;
        nodeManager.deleteNode(id);
        return assertEquals(null, nodeManager.getNode(id));
    });
    
    // Test 10: Create multiple nodes
    test('Create multiple nodes', () => {
        nodeManager.createNode('image-input', { x: 100, y: 100 });
        nodeManager.createNode('image-to-video', { x: 300, y: 100 });
        nodeManager.createNode('video-output', { x: 500, y: 100 });
        return assertEquals(3, nodeManager.getCount());
    });
    
    // Test 11: Serialize nodes
    test('Serialize nodes', () => {
        const serialized = nodeManager.serialize();
        return assertEquals(3, serialized.length);
    });
    
    // Test 12: Clear all
    test('Clear all nodes', () => {
        nodeManager.clear();
        return assertEquals(0, nodeManager.getCount());
    });
    
    // Test 13: Utils exist
    test('Utils loaded', () => {
        return assertNotNull(window.Utils);
    });
    
    // Test 14: Generate ID
    test('Utils.generateId', () => {
        const id = Utils.generateId('test');
        return id.startsWith('test_');
    });
    
    // Test 15: Deep clone
    test('Utils.deepClone', () => {
        const obj = { a: 1, b: { c: 2 } };
        const clone = Utils.deepClone(obj);
        clone.b.c = 3;
        return assertEquals(2, obj.b.c);
    });
    
    // Summary
    console.log(`\n=== Summary ===`);
    console.log(`Passed: ${passed}`);
    console.log(`Failed: ${failed}`);
    console.log(`Total: ${passed + failed}`);
    
    if (failed === 0) {
        console.log('\n✓ All tests passed!');
    } else {
        console.warn('\n⚠ Some tests failed!');
    }
})();