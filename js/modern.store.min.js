/*! tiny modern.store shim: store.get(key, def), store.set(key, val), store.remove(key), store.clear() */
(function(w){
  var s;
  try {
    s = w.localStorage; var t='__store_probe__'; s.setItem(t,'1'); s.removeItem(t);
  } catch(e) {
    s = {_d:{}};
    s.setItem=function(k,v){this._d[k]=String(v)};
    s.getItem=function(k){return this._d.hasOwnProperty(k)?this._d[k]:null};
    s.removeItem=function(k){delete this._d[k]};
    s.clear=function(){this._d={}};
  }
  w.store = {
    set:function(k,v){ s.setItem(k, JSON.stringify(v)); },
    get:function(k,def){ var v=s.getItem(k); try{ return v?JSON.parse(v):def; }catch(e){return def;} },
    remove:function(k){ s.removeItem(k); },
    clear:function(){ s.clear(); }
  };
})(window);
