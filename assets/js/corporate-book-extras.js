/**
 * Corporate Book 3D extras: spiral binding and calendar mode for the 3D viewer.
 * The viewer bundle ships empty hooks for both (initSpiral, refreshSpiral, isVertical);
 * this file fills them in.
 */
(function () {
  'use strict';
  var CB = window.CORPORATE_BOOK;
  if (!CB || !CB.FlipBook3D) {
    return;
  }
  var proto = CB.FlipBook3D.prototype;
  var isOn = function (value) {
    return value === true || value === 'true';
  };

  // Calendar mode: the book is bound at the top and pages flip upward.
  proto.isVertical = function () {
    return !!(this.app && this.app.options && isOn(this.app.options.calendarMode));
  };

  // Spiral binding: metal rings along the spine.
  proto.initSpiral = function () {
    this.hasSpiral = isOn(this.app.options.hasSpiral);
    if (!this.hasSpiral || !window.THREE || !this.bookWrapper) {
      return;
    }
    var material = new THREE.MeshPhongMaterial({ color: 0x8a8a8a, shininess: 90 });
    var ring = new THREE.TorusGeometry(1, 0.2, 8, 24);
    this.spiral = new THREE.Group();
    for (var i = 0; i < this.spiralCount; i++) {
      this.spiral.add(new THREE.Mesh(ring, material));
    }
    this.bookWrapper.add(this.spiral);
  };

  proto.refreshSpiral = function () {
    var size = this._defaultPageSize;
    if (!this.spiral || !size) {
      return;
    }
    var vertical = this.isVertical();
    var length = (vertical ? size.width : size.height) * 0.9;
    var radius = Math.max(this.pageOffset * 2.5, (vertical ? size.width : size.height) * 0.016);
    var rings = this.spiral.children;
    for (var i = 0; i < rings.length; i++) {
      var along = -length / 2 + length * i / (rings.length - 1);
      rings[i].scale.set(radius, radius, radius);
      // A torus lies flat by default; turn it so it wraps around the spine.
      if (vertical) {
        rings[i].position.set(along, 0, 0);
        rings[i].rotation.set(0, Math.PI / 2, 0);
      } else {
        rings[i].position.set(0, along, 0);
        rings[i].rotation.set(Math.PI / 2, 0, 0);
      }
    }
  };
})();
