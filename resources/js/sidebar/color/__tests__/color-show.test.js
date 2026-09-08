import { safeHexColor, colorShowImpl } from '../color-show.js';

describe('covers public behavior without internal references', () => {
    test('covers public behavior without internal references', () => {
        expect(safeHexColor('#2271b1')).toBe('#2271b1');
        expect(safeHexColor('#FFFFFF')).toBe('#FFFFFF');
    });

    test('covers public behavior without internal references', () => {
        expect(safeHexColor('#fff')).toBe('#fff');
        expect(safeHexColor('#ABC')).toBe('#ABC');
    });

    test('covers public behavior without internal references', () => {
        expect(safeHexColor('2271b1')).toBeNull();
        expect(safeHexColor('')).toBeNull();
        expect(safeHexColor('expression(alert(1))')).toBeNull();
        expect(safeHexColor('#fff; background:url(x)')).toBeNull();
        expect(safeHexColor(null)).toBeNull();
        expect(safeHexColor(123)).toBeNull();
    });
});

describe('covers public behavior without internal references', () => {
    test('preserves folder tree behavior', () => {
        expect(colorShowImpl.folderColorStyle({ color: '#ff8800' })).toBe('color:#ff8800');
        expect(colorShowImpl.folderColorStyle({ color: '' })).toBe('');
        expect(colorShowImpl.folderColorStyle({})).toBe('');
    });

    test('preserves folder tree behavior', () => {
        expect(colorShowImpl.folderColorFill({ color: '#ff8800' })).toBe('#ff880033');
        expect(colorShowImpl.folderColorFill({ color: '' })).toBe('none');
    });
});
